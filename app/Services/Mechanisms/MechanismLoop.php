<?php

namespace App\Services\Mechanisms;

use App\Actions\Mechanisms\CreateMechanism;
use App\Ai\Agents\MechanismDesigner;
use App\Ai\Agents\MechanismResearcher;
use App\Jobs\RunMechanismPhase;
use App\Models\AiUsageLog;
use App\Models\KnowledgeNode;
use App\Models\Mechanism;
use App\Models\MechanismFinding;
use App\Models\MechanismRound;
use App\Models\MechanismSource;
use App\Services\Ai\TextAi;
use App\Services\AiUsage\AiUsageRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use LogicException;

/**
 * 仕組みのループを段階ごとに進める（plotdesign.md の「ループ」）。段階は 1 つずつキューのジョブ（RunMechanismPhase）で動かす。
 *
 * 今は ① 目標の理解 → ② 知識検索 → ⑥ 結果報告 → 「まだ回すか」。③ 前提条件の確認〜⑤ 仕組みのプログラム作成は飛ばし、
 * 結果報告で「この周では行っていない」と AI に伝える。「まだ回す：はい」で、次の周への入力を付けて次の周を始める。
 */
class MechanismLoop
{
    /** 実装済みの段階の次の段階。ここにない段階へは進まない。 */
    private const NEXT_PHASE = [
        MechanismRound::PHASE_UNDERSTAND => MechanismRound::PHASE_SEARCH,
        MechanismRound::PHASE_SEARCH => MechanismRound::PHASE_REPORT,
        MechanismRound::PHASE_REPORT => MechanismRound::PHASE_DONE,
    ];

    /** 飛ばしている段階（結果報告で AI に伝える）。 */
    public const SKIPPED_PHASES = [
        MechanismRound::PHASE_PREMISE,
        MechanismRound::PHASE_CONFIRM,
        MechanismRound::PHASE_BUILD,
    ];

    /** AI の出力の一覧ごとの件数の上限。 */
    private const LIST_LIMITS = [
        'search_keywords' => 5,
        'done_criteria' => 5,
        'scope_out' => 5,
        'unclear_points' => 5,
        'remaining_missing' => 8,
        'web_queries' => 3,
    ];

    /** ② で使う知識の上限。 */
    public const MAX_USED_NODES = 10;

    public const TITLE_LENGTH = 30;

    /** 次の周への入力の文字数の上限。 */
    public const INSTRUCTION_MAX_LENGTH = 4000;

    public function __construct(
        private readonly AiUsageRecorder $usage,
        private readonly MechanismKnowledge $knowledge,
    ) {}

    /**
     * 周の今の段階をキューに入れる。
     */
    public function queue(MechanismRound $round): void
    {
        $round->update(['phase_status' => MechanismRound::STATUS_QUEUED, 'error' => null]);

        RunMechanismPhase::dispatch($round, $round->phase)->afterCommit();
    }

    /**
     * 失敗した段階をやり直す。
     */
    public function retry(MechanismRound $round): void
    {
        if ($round->phase_status !== MechanismRound::STATUS_FAILED) {
            throw new LogicException('失敗した段階だけやり直せます。');
        }

        $this->queue($round);
    }

    /**
     * 段階を 1 つ実行し、次の段階をキューに入れる。周の今の段階でない・待機中でないなら、何もしない（古いジョブ）。
     */
    public function run(MechanismRound $round, string $phase): void
    {
        $round->refresh();

        if ($round->phase !== $phase || $round->phase_status !== MechanismRound::STATUS_QUEUED) {
            return;
        }

        $round->update(['phase_status' => MechanismRound::STATUS_RUNNING]);

        match ($phase) {
            MechanismRound::PHASE_UNDERSTAND => $this->understand($round),
            MechanismRound::PHASE_SEARCH => $this->search($round),
            MechanismRound::PHASE_REPORT => $this->report($round),
        };

        $next = self::NEXT_PHASE[$phase];

        if ($next === MechanismRound::PHASE_DONE) {
            // 周の終わり。「まだ回しますか？」の答えを待つ。
            $round->update(['phase' => $next, 'phase_status' => MechanismRound::STATUS_WAITING]);

            return;
        }

        $round->update(['phase' => $next]);
        $this->queue($round);
    }

    /**
     * 段階の失敗を記録する（ジョブの failed から呼ぶ）。
     */
    public function fail(MechanismRound $round, string $phase, string $message): void
    {
        $round->refresh();

        if ($round->phase !== $phase) {
            return;
        }

        $round->update(['phase_status' => MechanismRound::STATUS_FAILED, 'error' => $message]);
    }

    /**
     * 「まだ回しますか？」に「いいえ」と答え、仕組みを完了にする。
     */
    public function finish(Mechanism $mechanism): void
    {
        $round = $this->awaitingRound($mechanism);

        $round->update(['continue' => false, 'phase_status' => MechanismRound::STATUS_DONE]);
        $mechanism->update(['status' => Mechanism::STATUS_COMPLETED]);
    }

    /**
     * 結果報告の「まだ足りない情報」（なければ ① の「はっきりしない点」）。「まだ回しますか？」で 1 つずつ答える。
     *
     * @return list<string>
     */
    public static function openQuestions(MechanismRound $round): array
    {
        $questions = $round->report['remaining_missing'] ?? [];

        return array_values($questions !== [] ? $questions : ($round->understanding['unclear_points'] ?? []));
    }

    /**
     * 「まだ回しますか？」に「はい」と答え、はっきりしない点への回答と次の周への入力を付けて次の周を始める。目標は前の周のまま変えない。
     *
     * @param  array<int|string, ?string>  $answers  openQuestions() の番号 => 回答（空なら答えない）
     */
    public function nextRound(Mechanism $mechanism, ?string $instruction, array $answers = []): MechanismRound
    {
        $round = $this->awaitingRound($mechanism);
        $instruction = trim((string) $instruction);
        $questions = self::openQuestions($round);

        $answered = collect($questions)
            ->map(fn (string $question, int $index) => ['question' => $question, 'answer' => trim((string) ($answers[$index] ?? ''))])
            ->filter(fn (array $qa) => $qa['answer'] !== '')
            ->values();

        if ($instruction === '' && $answered->isEmpty()) {
            throw new LogicException('はっきりしない点への回答か、次の周への入力が必要です。');
        }

        if (! self::canContinue($mechanism)) {
            throw new LogicException('周の上限（'.Mechanism::MAX_ROUNDS.' 周）に達しました。目標を分けて、新しい仕組みを作ってください。');
        }

        return DB::transaction(function () use ($mechanism, $round, $instruction, $answered) {
            $round->update(['continue' => true, 'phase_status' => MechanismRound::STATUS_DONE]);

            // 回答は、質問が出た周の不足情報として残す（次の周では「利用者の回答」として前提にする）。
            foreach ($answered as $qa) {
                $round->findings()->create([
                    'kind' => MechanismFinding::KIND_MISSING,
                    'content' => $qa['question'],
                    'question' => $qa['question'],
                    'answer' => $qa['answer'],
                    'answered_at' => now(),
                ]);
            }

            $next = $mechanism->rounds()->create([
                'number' => $round->number + 1,
                'phase' => MechanismRound::PHASE_UNDERSTAND,
                'phase_status' => MechanismRound::STATUS_QUEUED,
                'instruction' => $instruction !== '' ? $instruction : null,
            ]);

            $mechanism->update(['current_round' => $next->number]);
            $this->queue($next);

            return $next;
        });
    }

    /**
     * 「いいえ」で完了にした仕組みを、「まだ回しますか？」の答えを待つ状態に戻す。
     */
    public function reopen(Mechanism $mechanism): void
    {
        $round = $mechanism->latestRound()->firstOrFail();

        if (! self::canReopen($mechanism, $round)) {
            throw new LogicException('「いいえ」で完了にした仕組みだけ再開できます。');
        }

        DB::transaction(function () use ($mechanism, $round) {
            $round->update(['continue' => null, 'phase_status' => MechanismRound::STATUS_WAITING]);
            $mechanism->update(['status' => Mechanism::STATUS_ACTIVE]);
        });
    }

    public static function canReopen(Mechanism $mechanism, MechanismRound $round): bool
    {
        return $mechanism->status === Mechanism::STATUS_COMPLETED
            && $round->phase === MechanismRound::PHASE_DONE
            && $round->continue === false;
    }

    public static function isAwaitingContinue(MechanismRound $round): bool
    {
        return $round->phase === MechanismRound::PHASE_DONE && $round->phase_status === MechanismRound::STATUS_WAITING;
    }

    public static function canContinue(Mechanism $mechanism): bool
    {
        return $mechanism->current_round < Mechanism::MAX_ROUNDS;
    }

    private function awaitingRound(Mechanism $mechanism): MechanismRound
    {
        $round = $mechanism->latestRound()->firstOrFail();

        if ($mechanism->status !== Mechanism::STATUS_ACTIVE || ! self::isAwaitingContinue($round)) {
            throw new LogicException('結果報告の後でだけ答えられます。');
        }

        return $round;
    }

    private function understand(MechanismRound $round): void
    {
        [$output, $model] = $this->ask(new MechanismDesigner(MechanismRound::PHASE_UNDERSTAND), $round, [
            ...$this->baseInput($round),
            'previous_rounds' => $this->previousRounds($round) ?: null,
        ]);

        $understanding = [
            'title' => mb_substr(trim((string) ($output['title'] ?? '')), 0, self::TITLE_LENGTH),
            'restated_goal' => trim((string) ($output['restated_goal'] ?? '')),
            'done_criteria' => $this->strings($output, 'done_criteria'),
            'scope_out' => $this->strings($output, 'scope_out'),
            'unclear_points' => $this->strings($output, 'unclear_points'),
            'search_keywords' => $this->strings($output, 'search_keywords'),
            'model' => $model,
        ];

        $round->update(['understanding' => $understanding]);

        // 名前を書かなかった（目標の先頭を仮の名前にした）ときだけ、AI の付けた名前にする。
        $mechanism = $round->mechanism;
        if ($understanding['title'] !== '' && $mechanism->title === CreateMechanism::titleFromGoal($mechanism->goal)) {
            $mechanism->update(['title' => $understanding['title']]);
        }
    }

    /**
     * ② 知識検索。目標・言い換え・検索語でプロジェクト内の知識を探し、使うものと、知識だけで足りるかを AI に判断させる。
     */
    private function search(MechanismRound $round): void
    {
        $queries = $this->knowledge->queries($round);
        $nodes = $this->knowledge->find($round, $queries);

        [$output, $model] = $this->ask(new MechanismResearcher, $round, [
            ...$this->baseInput($round),
            'understanding' => $this->understandingForPrompt($round),
            'knowledge' => $this->knowledge->forPrompt($nodes),
        ]);

        // 一覧にない id は捨てる（AI が知識を作らないように）。
        $byId = $nodes->keyBy('id');
        $used = collect($output['used_nodes'] ?? [])
            ->filter(fn ($item) => is_array($item) && $byId->has((int) ($item['id'] ?? 0)))
            ->unique(fn (array $item) => (int) $item['id'])
            ->take(self::MAX_USED_NODES)
            ->map(fn (array $item) => ['id' => (int) $item['id'], 'reason' => trim((string) ($item['reason'] ?? ''))])
            ->values();

        $enough = (bool) ($output['enough'] ?? false);

        DB::transaction(function () use ($round, $queries, $nodes, $used, $byId, $enough, $output, $model) {
            // やり直したときに、前の結果の根拠が残らないように。
            $round->sources()->where('source_type', MechanismSource::TYPE_KNOWLEDGE)->delete();

            foreach ($used as $item) {
                /** @var KnowledgeNode $node */
                $node = $byId[$item['id']];
                $round->sources()->create([
                    'source_type' => MechanismSource::TYPE_KNOWLEDGE,
                    'knowledge_node_id' => $node->id,
                    'title' => $node->title,
                    'excerpt' => Str::limit((string) $node->description, 1000),
                    'reason' => $item['reason'],
                ]);
            }

            $round->update(['search' => [
                'queries' => $queries,
                'found' => $nodes->map(fn (KnowledgeNode $node) => [
                    'id' => $node->id,
                    'title' => $node->title,
                    'type' => $node->node_type,
                    'status' => $node->status,
                    'match_reasons' => $node->match_reasons,
                    'similarity' => $node->best_similarity,
                ])->all(),
                'enough' => $enough,
                'why_not_enough' => $enough ? '' : trim((string) ($output['why_not_enough'] ?? '')),
                'web_queries' => $enough ? [] : $this->strings($output, 'web_queries'),
                'model' => $model,
            ]]);
        });
    }

    private function report(MechanismRound $round): void
    {
        $search = $round->search ?? [];

        [$output, $model] = $this->ask(new MechanismDesigner(MechanismRound::PHASE_REPORT), $round, [
            ...$this->baseInput($round),
            'previous_rounds' => $this->previousRounds($round) ?: null,
            'understanding' => $this->understandingForPrompt($round),
            'used_knowledge' => $this->usedKnowledge($round),
            'search' => [
                'found_count' => count($search['found'] ?? []),
                'enough' => $search['enough'] ?? false,
                'why_not_enough' => $search['why_not_enough'] ?? '',
                'web_queries' => $search['web_queries'] ?? [],
                'web_searched' => false,
            ],
            'skipped_phases' => array_map(fn (string $p) => MechanismRound::PHASE_LABELS[$p], self::SKIPPED_PHASES),
        ]);

        $round->update(['report' => [...$this->normalizeReport($output, $round), 'model' => $model]]);
    }

    /**
     * AI に入力（JSON）を渡し、構造化出力とモデル名を返す。前の段階の結果は、会話の履歴ではなく入力で毎回渡す。
     *
     * @param  array<string, mixed>  $input
     * @return array{array<string, mixed>, string}
     */
    private function ask(Agent $agent, MechanismRound $round, array $input): array
    {
        $prompt = json_encode(array_filter($input, fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $text = TextAi::fromConfig();

        /** @var AgentResponse $response */
        [$response, $model] = $this->usage->within(AiUsageLog::FEATURE_MECHANISM, $round, fn () => $text->models()->attempt(
            fn (?string $model) => $agent->prompt($prompt, provider: $text->provider, model: $model),
            purpose: $round->phaseLabel(),
            context: ['mechanism_round_id' => $round->id],
        ));

        return [$response->toArray(), $response->meta->model ?? $model ?? $text->provider->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseInput(MechanismRound $round): array
    {
        return [
            'goal' => $round->mechanism->goal,
            'title' => $round->mechanism->title,
            'round' => $round->number,
            // 前の周のはっきりしない点への、利用者の回答（確定した前提）。
            'answers' => $round->receivedAnswers() ?: null,
            'instruction' => $round->instruction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function understandingForPrompt(MechanismRound $round): array
    {
        return Arr::except($round->understanding ?? [], ['model', 'title']);
    }

    /**
     * @return list<array{id: int, title: ?string, reason: ?string}>
     */
    private function usedKnowledge(MechanismRound $round): array
    {
        return $round->sources()
            ->where('source_type', MechanismSource::TYPE_KNOWLEDGE)
            ->orderBy('id')
            ->get()
            ->map(fn (MechanismSource $source) => ['id' => $source->knowledge_node_id, 'title' => $source->title, 'reason' => $source->reason])
            ->all();
    }

    /**
     * 前の周の要点（次の周への入力・言い換え・完了条件・使った知識・結果報告）。古い順。
     *
     * @return list<array<string, mixed>>
     */
    private function previousRounds(MechanismRound $round): array
    {
        return $round->mechanism->rounds()
            ->where('number', '<', $round->number)
            ->get()
            ->map(fn (MechanismRound $previous) => array_filter([
                'round' => $previous->number,
                'answers' => $previous->receivedAnswers() ?: null,
                'instruction' => $previous->instruction,
                'restated_goal' => $previous->understanding['restated_goal'] ?? null,
                'done_criteria' => $previous->understanding['done_criteria'] ?? null,
                'used_knowledge' => array_map(fn (array $k) => Arr::only($k, ['id', 'title']), $this->usedKnowledge($previous)) ?: null,
                'report' => isset($previous->report) ? Arr::except($previous->report, 'model') : null,
            ], fn ($value) => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    /**
     * 完了条件は ① の項目に合わせる（AI が足した・落とした項目は直す）。状態が決まった値でなければ未確認。
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function normalizeReport(array $output, MechanismRound $round): array
    {
        $checks = collect($output['criteria_check'] ?? [])->filter(fn ($c) => is_array($c))->keyBy(fn (array $c) => trim((string) ($c['criterion'] ?? '')));

        $criteriaCheck = array_map(function (string $criterion) use ($checks) {
            $check = $checks->get($criterion, []);
            $status = $check['status'] ?? null;

            return [
                'criterion' => $criterion,
                'status' => array_key_exists((string) $status, MechanismDesigner::CRITERION_LABELS) ? $status : MechanismDesigner::CRITERION_UNKNOWN,
                'note' => trim((string) ($check['note'] ?? '')),
            ];
        }, $round->understanding['done_criteria'] ?? []);

        return [
            'summary' => trim((string) ($output['summary'] ?? '')),
            'criteria_check' => $criteriaCheck,
            'remaining_missing' => $this->strings($output, 'remaining_missing'),
            'next_suggestion' => trim((string) ($output['next_suggestion'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function strings(array $output, string $key): array
    {
        return collect($output[$key] ?? [])
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->take(self::LIST_LIMITS[$key])
            ->values()
            ->all();
    }
}
