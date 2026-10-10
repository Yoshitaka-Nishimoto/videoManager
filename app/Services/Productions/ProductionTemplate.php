<?php

namespace App\Services\Productions;

/**
 * 制作を作るときの選択肢と、場面の部品の構成（型）から最初の場面を組み立てるひな形。
 *
 * 段階 1：台本はまだ仮の文。字幕・ナレーションは段階 2（Gemini による台本の候補）で入れる。
 */
final class ProductionTemplate
{
    /** 場面の部品の構成の型。scenes は [場面の種類, 場面の名前, 長さの配分]。 */
    public const STRUCTURES = [
        'explain' => [
            'label' => '解説型',
            'scenes' => [
                ['remotion.title', '導入', 2],
                ['remotion.text', '要点', 3],
                ['remotion.diagram', '仕組み', 3],
                ['remotion.title', 'まとめ', 2],
            ],
        ],
        'howto' => [
            'label' => '手順型',
            'scenes' => [
                ['remotion.title', '導入', 2],
                ['remotion.diagram', '流れ', 4],
                ['remotion.text', '手順の要点', 3],
                ['remotion.title', 'まとめ', 1],
            ],
        ],
        'compare' => [
            'label' => '比較型',
            'scenes' => [
                ['remotion.title', '導入', 2],
                ['remotion.chart', '比較', 4],
                ['remotion.text', '違いの要点', 3],
                ['remotion.title', 'まとめ', 1],
            ],
        ],
        'short' => [
            'label' => '短尺型',
            'scenes' => [
                ['remotion.title', '導入', 1],
                ['remotion.text', '要点', 3],
            ],
        ],
    ];

    /** ジャンルごとの構成の初期値（選び直せる）。 */
    public const DEFAULT_STRUCTURE_FOR_GENRE = [
        'ai' => 'explain',
        'video_creation' => 'howto',
        'healthy_longevity' => 'compare',
        'other' => 'explain',
    ];

    public const AUDIENCES = [
        'beginner' => '初心者',
        'practitioner' => '実務者',
        'expert' => '専門家',
    ];

    /** 目標の長さ（秒 => 表示名）。 */
    public const DURATIONS = [
        30 => '30秒',
        60 => '60秒',
        90 => '90秒',
    ];

    public const TONES = [
        'calm' => '落ち着いた解説',
        'upbeat' => 'テンポよく',
        'friendly' => '親しみやすく',
    ];

    public const RATIO_LABELS = [
        '1280:720' => '横長（16:9）',
        '720:1280' => '縦長（9:16）',
        '960:960' => '正方形（1:1）',
    ];

    /** 1 つの場面の最短の長さ（秒）。 */
    private const MIN_SCENE_SECONDS = 1.5;

    public static function structureLabel(string $structure): string
    {
        return self::STRUCTURES[$structure]['label'] ?? $structure;
    }

    /**
     * 目標の長さを、構成の配分に合わせて場面に分ける（0.5 秒単位。合計は目標の長さに合わせる）。
     *
     * @return list<float>
     */
    public static function sceneDurations(string $structure, int $targetSeconds): array
    {
        $weights = array_column(self::STRUCTURES[$structure]['scenes'], 2);
        $total = array_sum($weights);

        $durations = array_map(
            fn (int $weight) => max(self::MIN_SCENE_SECONDS, round($targetSeconds * $weight / $total * 2) / 2),
            $weights,
        );

        // 端数は最後の場面で調整する。
        $last = array_key_last($durations);
        $durations[$last] = max(self::MIN_SCENE_SECONDS, $durations[$last] + $targetSeconds - array_sum($durations));

        return $durations;
    }

    /**
     * 構成の型から、最初の場面（仮の内容）を作るための属性を返す。
     *
     * 見出しや箇条書きは、タイトル・伝えたいこと・設計判断の名称から仮に作る。
     * グラフの数値は作らない（数値は根拠のあるものだけを使う決まりのため、人が入れる）。
     *
     * @return list<array<string, mixed>>
     */
    public static function scenes(string $structure, int $targetSeconds, string $title, ?string $brief, string $decisionTitle): array
    {
        $points = self::points($brief);
        $durations = self::sceneDurations($structure, $targetSeconds);
        $specs = self::STRUCTURES[$structure]['scenes'];
        $lastIndex = array_key_last($specs);

        return array_map(function (array $spec, int $index) use ($points, $durations, $title, $decisionTitle, $lastIndex) {
            [$type, $sceneTitle] = $spec;

            return [
                'scene_key' => 's'.($index + 1),
                'position' => $index + 1,
                'scene_type' => $type,
                'title' => $sceneTitle,
                'duration_seconds' => $durations[$index],
                'content' => match ($type) {
                    'remotion.title' => ['title' => $index === $lastIndex && $index > 0
                        ? ['heading' => mb_substr($decisionTitle, 0, 30), 'subheading' => null, 'layout' => 'lower_third']
                        : ['heading' => mb_substr($title, 0, 30), 'subheading' => null, 'layout' => 'center']],
                    'remotion.text' => ['text' => ['heading' => $sceneTitle, 'items' => $points, 'reveal' => 'one_by_one']],
                    'remotion.diagram' => ['diagram' => self::diagram($points)],
                    'remotion.chart' => ['chart' => [
                        'chart_type' => 'bar',
                        'title' => '（比べる数値を入れてください）',
                        'unit' => null,
                        'labels' => ['項目A', '項目B'],
                        'series' => [['name' => '値', 'values' => [0, 0]]],
                    ]],
                    default => null,
                },
            ];
        }, $specs, array_keys($specs));
    }

    /**
     * 伝えたいことを文で区切り、箇条書きの仮の項目にする（最大 3 つ、各 40 文字）。
     *
     * @return list<string>
     */
    private static function points(?string $brief): array
    {
        $sentences = collect(preg_split('/[。\n]+/u', (string) $brief))
            ->map(fn (string $sentence) => trim($sentence))
            ->filter()
            ->take(3)
            ->map(fn (string $sentence) => mb_substr($sentence, 0, 40))
            ->values()
            ->all();

        return $sentences !== [] ? $sentences : ['（台本の候補を選ぶと入ります）'];
    }

    /**
     * 箇条書きの仮の項目を、左から右へつながる図にする（ラベルは 15 文字）。
     *
     * @param  list<string>  $points
     * @return array<string, mixed>
     */
    private static function diagram(array $points): array
    {
        // 図の箱は 2〜8 個（scene_content.md）。仮の項目が 1 つなら、つなぎ先の箱を足す。
        if (count($points) < 2) {
            $points[] = '（つながる要素）';
        }

        $nodes = array_map(fn (string $point, int $i) => ['id' => chr(97 + $i), 'label' => mb_substr($point, 0, 15)], $points, array_keys($points));
        $ids = array_column($nodes, 'id');
        $edges = array_map(fn (string $from, string $to) => ['from' => $from, 'to' => $to, 'label' => null], array_slice($ids, 0, -1), array_slice($ids, 1));

        return ['layout' => 'horizontal', 'nodes' => $nodes, 'edges' => $edges, 'reveal_order' => $ids];
    }
}
