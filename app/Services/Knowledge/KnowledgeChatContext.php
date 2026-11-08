<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeNode;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 知識への質問（KnowledgeAssistant）に渡す根拠。本人が見られる概念（既定は確認済み。ジャンルを絞らないときは状態を選べる）と、本人の個人のファイルの分析結果だけを入れる。
 * 他の利用者の個人の知識は入れない（visibleTo）。利用者の名前など、本人を特定する情報も入れない。
 *
 * 概念は、質問文と本人の個人のデータに意味の近いものを pgvector で選ぶ（概念が増えても、関係するものが届くように）。
 * 埋め込みを使わない設定のとき・作れなかったときは、新しい順に渡す。
 */
class KnowledgeChatContext
{
    /** 埋め込みで選べないときに渡す確認済みの概念の上限（新しい順）。 */
    public const MAX_CONCEPTS = 300;

    /** 意味の近さで選ぶ概念の上限と、1 つの文（質問・個人のファイル）ごとに探す件数。 */
    public const MAX_RELEVANT_CONCEPTS = 60;

    public const RELEVANT_PER_QUERY = 40;

    /**
     * 意味の近さで選ぶときの類似度の下限の既定値。質問欄で利用者が変えられる（LOWEST_SIMILARITY〜1）。
     * 0.3 では無関係な概念も 0.55〜0.62 で通ってしまった（2026-10-30、確認済み 9 件で確認）ため、0.7 にする。
     */
    public const MIN_SIMILARITY = 0.6;

    /** 利用者が設定できる類似度の下限の最小値（知識一覧の「意味で検索」と同じ）。 */
    public const LOWEST_SIMILARITY = 0.6;

    /** 個人のファイル 1 件ごとに渡すデータ（表）の上限（文字数）。 */
    public const MAX_DATA_LENGTH = 6000;

    public function __construct(private readonly KnowledgeEmbedder $embedder) {}

    /**
     * @param  ?string  $genre  ジャンル（VideoProduction::GENRES のキー）。指定すると、概念と個人のファイルをそのジャンルに絞る
     * @param  ?string  $question  質問文。意味の近い概念を選ぶのに使う
     * @param  float  $minSimilarity  意味の近さで選ぶときの類似度の下限
     * @param  string  $status  根拠にする概念の状態（KnowledgeNode::STATUS_*。KnowledgeChat::STATUS_ANY なら状態で絞らない）
     * @return array{text: string, similarities: array<int, float>} 根拠の文と、意味の近さで選んだ概念の類似度（id => 0〜1。埋め込みのない概念・新しい順で選んだときは入れない）
     */
    public function build(User $user, ?string $genre = null, ?string $question = null, float $minSimilarity = self::MIN_SIMILARITY, string $status = KnowledgeNode::STATUS_CONFIRMED): array
    {
        $files = $this->personalFiles($user, $genre);

        $concepts = $this->concepts($user, $genre, [
            ...($question !== null && trim($question) !== '' ? [trim($question)] : []),
            ...$files->map(fn (array $file) => $this->describeFile(...$file, withData: false)),
        ], $minSimilarity, $status);

        $conceptsText = $concepts
            ->map(fn (KnowledgeNode $node) => "{$node->id}：{$node->title}".($node->description ? ' — '.Str::limit($node->description, 120) : ''))
            ->implode("\n");

        $filesText = $files->map(fn (array $file) => $this->describeFile(...$file))->implode("\n\n");

        return [
            'text' => implode("\n", [
                '## 根拠の概念（状態：'.($status === KnowledgeChat::STATUS_ANY ? 'すべて（確認済み・候補・廃止）' : KnowledgeNode::statusLabelFor($status)).'。id：名称 — 説明。質問と個人のデータに関係の深い順）',
                $conceptsText !== '' ? $conceptsText : '（なし）',
                '',
                '## 本人の個人のデータ',
                $filesText !== '' ? $filesText : '（なし）',
            ]),
            'similarities' => $concepts
                ->filter(fn (KnowledgeNode $node) => $node->similarity !== null)
                ->mapWithKeys(fn (KnowledgeNode $node) => [$node->id => round((float) $node->similarity, 4)])
                ->all(),
        ];
    }

    /**
     * 本人が見られる、$status の状態の概念（既定は確認済み）。$queries があれば、どれかと意味の近い順に選ぶ。
     *
     * @param  list<string>  $queries  質問文や個人のファイルの要約
     * @return Collection<int, KnowledgeNode>
     */
    public function concepts(User $user, ?string $genre = null, array $queries = [], float $minSimilarity = self::MIN_SIMILARITY, string $status = KnowledgeNode::STATUS_CONFIRMED): Collection
    {
        if ($queries === [] || ! $this->embedder->enabled()) {
            return $this->conceptsIn($user, $genre, $status)->latest('id')->limit(self::MAX_CONCEPTS)->get();
        }

        try {
            return $this->relevantConcepts($user, $genre, $queries, $minSimilarity, $status);
        } catch (Throwable $e) {
            Log::warning('知識への質問の根拠を意味の近さで選べなかったため、新しい順に選びます。', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->conceptsIn($user, $genre, $status)->latest('id')->limit(self::MAX_CONCEPTS)->get();
        }
    }

    /**
     * 文ごとに意味の近い概念を探し、いちばん高い類似度の順に並べる。埋め込みのまだない概念（作ったばかりなど）も後ろに加える。
     *
     * @param  list<string>  $queries
     * @return Collection<int, KnowledgeNode>
     */
    private function relevantConcepts(User $user, ?string $genre, array $queries, float $minSimilarity, string $status): Collection
    {
        $relevant = collect($queries)
            ->flatMap(fn (string $query) => $this->conceptsIn($user, $genre, $status, $this->embedder->similarTo($this->embedder->vectorFor($query), $minSimilarity))
                ->limit(self::RELEVANT_PER_QUERY)
                ->get())
            ->sortByDesc('similarity')
            ->unique('id')
            ->take(self::MAX_RELEVANT_CONCEPTS);

        $notEmbedded = $this->conceptsIn($user, $genre, $status)
            ->whereNull('embedding')
            ->latest('id')
            ->limit(self::MAX_RELEVANT_CONCEPTS)
            ->get();

        return $relevant->concat($notEmbedded)->values();
    }

    /**
     * tinker で確かめる用：本人が見られる、$status の状態（既定は確認済み）の概念のクエリ。
     *
     * @param  ?string  $genre  ジャンルのキー（VideoProduction::GENRES のキー。例 'ai'）
     * @param  ?Builder<KnowledgeNode>  $query  土台にするクエリ（null なら KnowledgeNode::query()）
     * @return Builder<KnowledgeNode>
     */
    public static function userSet(User $user, ?string $genre = null, ?Builder $query = null, string $status = KnowledgeNode::STATUS_CONFIRMED): Builder
    {
        return app(self::class)->conceptsIn($user, $genre, $status, $query);
    }

    /**
     * @param  ?Builder<KnowledgeNode>  $query
     * @return Builder<KnowledgeNode>
     */
    private function conceptsIn(User $user, ?string $genre, string $status, ?Builder $query = null): Builder
    {
        return ($query ?? KnowledgeNode::query())
            ->visibleTo($user->id)
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->when($status !== KnowledgeChat::STATUS_ANY, fn ($query) => $query->where('status', $status))
            ->when($genre, fn ($query) => $query->inGenre($genre));
    }

    /**
     * 本人の個人のファイルと、その最新の完了した分析。
     *
     * @return Collection<int, array{Video, VideoAnalysis}>
     */
    private function personalFiles(User $user, ?string $genre): Collection
    {
        return Video::query()
            ->visibleTo($user->id)
            ->where('owner_id', $user->id)
            ->where('source_type', Video::SOURCE_FILE)
            ->when($genre, fn ($query) => $query->where('genre', $genre))
            ->oldest('id')
            ->get()
            ->map(fn (Video $video) => [$video, $video->analyses()->where('status', VideoAnalysis::STATUS_COMPLETED)->latest('version')->first()])
            ->filter(fn (array $file) => $file[1] !== null)
            ->values();
    }

    /**
     * @param  bool  $withData  データの表も入れるか（意味の近い概念を探すときは、要約と注目する項目だけにする）
     */
    private function describeFile(Video $video, VideoAnalysis $analysis, bool $withData = true): string
    {
        $points = collect($analysis->content['key_points'] ?? [])
            ->map(fn (array $point) => '- '.$point['point'].(filled($point['quote'] ?? null) ? "（データ：{$point['quote']}）" : ''))
            ->implode("\n");

        return implode("\n", array_filter([
            "### {$video->title}（".($video->genreLabel() ?? 'ジャンル未設定').'、登録日 '.$video->created_at->toDateString().'）',
            '要約：'.($analysis->summary ?? ''),
            $points !== '' ? "注目する項目：\n{$points}" : null,
            $withData && filled($analysis->source_text) ? "データ：\n<<<\n".Str::limit($analysis->source_text, self::MAX_DATA_LENGTH)."\n>>>" : null,
        ]));
    }
}
