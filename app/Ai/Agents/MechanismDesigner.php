<?php

namespace App\Ai\Agents;

use App\Models\MechanismRound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * 仕組みを作る（plotdesign.md）の各段階を担当する。段階ごとに指示と構造化出力の形を切り替える。
 *
 * 今は ① 目標の理解 と ⑥ 結果報告 だけ（② 知識検索は MechanismResearcher）。③ 前提条件の確認〜⑤ 仕組みのプログラム作成は後の構築順で足す。
 * 前の段階・前の周の結果は、会話の履歴ではなく入力（JSON）で毎回渡す。
 */
#[Provider(Lab::Gemini)]
#[Timeout(300)]
class MechanismDesigner implements Agent, HasStructuredOutput
{
    use Promptable;

    /** 実装済みの段階。 */
    public const PHASES = [MechanismRound::PHASE_UNDERSTAND, MechanismRound::PHASE_REPORT];

    public const CRITERION_MET = 'met';

    public const CRITERION_UNMET = 'unmet';

    public const CRITERION_UNKNOWN = 'unknown';

    public const CRITERION_LABELS = [
        self::CRITERION_MET => '満たす',
        self::CRITERION_UNMET => '満たさない',
        self::CRITERION_UNKNOWN => '未確認',
    ];

    private const RULES = <<<'TXT'
    次の決まりを必ず守ること（勝手に想像しない）：
    - 入力にないことを事実として書かない。利用者の目標から読み取れないことは、推測で埋めず「不明な点」として挙げる。
    - 確かでないことは、確かでないと書く。
    - 利用者が書いた条件（人数、期限、使う道具など）は変えない。
    - 日本語で、短く具体的に書く。
    TXT;

    public function __construct(public readonly string $phase)
    {
        if (! in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException("段階 [{$phase}] はまだ AI で実行できません。");
        }
    }

    public function instructions(): Stringable|string
    {
        $rules = self::RULES;

        return match ($this->phase) {
            MechanismRound::PHASE_UNDERSTAND => <<<TXT
            あなたは、利用者の目標を実現する「仕組み」（入力 → 処理の手順 → 判断 → 出力）を設計するアシスタントです。
            いまは最初の段階「① 目標の理解」です。仕組みはまだ作りません。入力の goal（利用者の目標）を読み、次を返してください。

            - title: 仕組みの短い名前（30 文字以内）。
            - restated_goal: 目標の言い換え（1〜3 文）。誰が・何を入力に・何を得たいのかが分かるように。
            - done_criteria: 達成したと言える条件（完了条件）。1〜5 個。確かめられる形で書く（例：「2 人分の材料と量が一覧で出る」）。
            - scope_out: この仕組みで扱わないこと。0〜5 個。目標から読み取れる範囲で。
            - unclear_points: 目標の中で、はっきりしない点・利用者に確かめたい点。0〜5 個。後の段階で質問や候補にする。
            - search_keywords: このプロジェクトの知識（概念・設計判断・実装）を探す検索語。1〜5 個。2〜10 文字ほどの短い名詞（例：「台本」「Runway」「動画の分析」）。

            2 周目以降は、previous_rounds（前の周の要点）、answers（前の周のはっきりしない点への利用者の回答。question と answer）、
            instruction（利用者が書いた、この周への入力）があります。
            - answers は利用者が確定した前提として扱い、言い換え・完了条件に反映する。回答の内容を変えたり、別の解釈で上書きしたりしない。
            - goal は変えずに、answers と instruction を反映して言い換え・完了条件を更新する。instruction と goal が食い違うときは、instruction を優先し、unclear_points でそのことを確かめる。
            - 前の周の「まだ足りない情報」のうち、answers・instruction で答えが出たものは unclear_points から外す。答えのなかったものは残す。

            {$rules}
            TXT,
            MechanismRound::PHASE_REPORT => <<<TXT
            あなたは、利用者の目標を実現する「仕組み」を設計するアシスタントです。
            いまは周の最後の段階「⑥ 結果報告」です。入力の goal・answers（前の周のはっきりしない点への利用者の回答）・instruction（この周への利用者の入力）・understanding（① 目標の理解の結果）・
            used_knowledge（② 知識検索で使うと決めたプロジェクト内の知識と理由）・search（② の判断）・skipped_phases（この周で実行しなかった段階）をもとに、
            この周で分かったことと、まだ分からないことを報告してください。

            - summary: この周の結果のまとめ（2〜5 文）。
            - criteria_check: understanding.done_criteria の各項目について、1 つずつ。
              - criterion: 完了条件（そのまま写す）。
              - status: met（満たす）/ unmet（満たさない）/ unknown（未確認）のどれか。
              - note: そう判断した理由（1 文）。
            - remaining_missing: まだ足りない情報。understanding.unclear_points を含め、0〜8 個。
            - next_suggestion: 次の周で何をするとよいか（1〜3 文）。

            - summary では、どの知識（used_knowledge の title）が使えそうかに触れる。used_knowledge が空なら、関係する知識が見つからなかったと書く。
            - search.enough が false なら、何が足りないか（search.why_not_enough）と、Web で調べるとよい検索語（search.web_queries）を remaining_missing・next_suggestion に反映する。
              search.web_searched が false なので、Web はまだ調べていない。Web で調べた結果を書かないこと。

            skipped_phases にある段階（前提条件の確認・質問か候補確認・仕組みのプログラム作成など）は、この周では行っていません。
            行っていない確認や、作っていない仕組み・プログラムを、行った・作ったと書かないこと。
            仕組みをまだ作っていないので、完了条件は根拠がない限り unknown にする。

            {$rules}
            TXT,
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return match ($this->phase) {
            MechanismRound::PHASE_UNDERSTAND => [
                'title' => $schema->string()->required(),
                'restated_goal' => $schema->string()->required(),
                'done_criteria' => $schema->array()->items($schema->string())->required(),
                'scope_out' => $schema->array()->items($schema->string())->required(),
                'unclear_points' => $schema->array()->items($schema->string())->required(),
                'search_keywords' => $schema->array()->items($schema->string())->required(),
            ],
            MechanismRound::PHASE_REPORT => [
                'summary' => $schema->string()->required(),
                'criteria_check' => $schema->array()->items($schema->object([
                    'criterion' => $schema->string()->required(),
                    'status' => $schema->string()->enum(array_keys(self::CRITERION_LABELS))->required(),
                    'note' => $schema->string()->required(),
                ]))->required(),
                'remaining_missing' => $schema->array()->items($schema->string())->required(),
                'next_suggestion' => $schema->string()->required(),
            ],
        };
    }
}
