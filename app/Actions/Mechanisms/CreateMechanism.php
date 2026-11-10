<?php

namespace App\Actions\Mechanisms;

use App\Models\Mechanism;
use App\Models\MechanismRound;
use App\Models\User;
use App\Services\Mechanisms\MechanismLoop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 目標から仕組みを作り、1 周目（① 目標の理解・待機中）を用意する（plotdesign.md）。
 *
 * 作ったら ① 目標の理解をキューに入れる（MechanismLoop）。
 */
class CreateMechanism
{
    public function __construct(private readonly MechanismLoop $loop) {}

    /**
     * @param  array{goal: string, title?: ?string}  $input
     */
    public function handle(array $input, User $user): Mechanism
    {
        $goal = trim($input['goal']);
        $title = trim((string) ($input['title'] ?? ''));

        return DB::transaction(function () use ($goal, $title, $user) {
            $mechanism = Mechanism::query()->create([
                'title' => $title !== '' ? $title : self::titleFromGoal($goal),
                'goal' => $goal,
                'status' => Mechanism::STATUS_ACTIVE,
                'current_round' => 1,
                'owner_id' => $user->id,
            ]);

            $round = $mechanism->rounds()->create([
                'number' => 1,
                'phase' => MechanismRound::PHASE_UNDERSTAND,
                'phase_status' => MechanismRound::STATUS_QUEUED,
            ]);

            // ジョブはトランザクションの確定後に入る（afterCommit）。
            $this->loop->queue($round);

            return $mechanism;
        });
    }

    /**
     * 名前を書かなかったときの仮の名前（目標の 1 行目の先頭）。① 目標の理解で AI が付け直す。
     */
    public static function titleFromGoal(string $goal): string
    {
        $firstLine = Str::of($goal)->before("\n")->squish()->toString();

        // Str::limit は全角を幅 2 と数えるので、文字数で切る。
        return mb_strlen($firstLine) > Mechanism::TITLE_FROM_GOAL_LENGTH
            ? mb_substr($firstLine, 0, Mechanism::TITLE_FROM_GOAL_LENGTH).'…'
            : $firstLine;
    }
}
