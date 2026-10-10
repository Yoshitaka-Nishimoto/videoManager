<?php

namespace App\Actions\Productions;

use App\Models\KnowledgeNode;
use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use App\Models\User;
use App\Models\VideoProduction;
use App\Services\Productions\ProductionTemplate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 設計判断から制作を作り、選んだ構成の型で最初の制作案（候補・第 1 版）と場面を用意する。
 *
 * 段階 1：場面の内容は仮の文。台本（字幕・ナレーション）は段階 2 で Gemini の候補から選ぶ。
 */
class CreateProduction
{
    /**
     * @param  array{genre: string, structure: string, audience: string, duration: int, tone: string, ratio: string, title: string, brief: ?string}  $input
     */
    public function handle(KnowledgeNode $decision, array $input, User $user): VideoProduction
    {
        if ($decision->node_type !== KnowledgeNode::TYPE_DECISION) {
            throw new InvalidArgumentException('制作は設計判断から作ります。');
        }

        return DB::transaction(function () use ($decision, $input, $user) {
            $production = VideoProduction::query()->create([
                'decision_node_id' => $decision->id,
                'genre' => $input['genre'],
                'title' => $input['title'],
                'brief' => $input['brief'],
                'ratio' => $input['ratio'],
                'target_duration_seconds' => $input['duration'],
                'status' => VideoProduction::STATUS_ACTIVE,
                'created_by' => $user->id,
            ]);

            $plan = $production->plans()->create([
                'version' => 1,
                'status' => ProductionPlan::STATUS_CANDIDATE,
                'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
                'summary' => ProductionTemplate::structureLabel($input['structure']).'の構成で作った最初の案（台本は仮）。',
                'settings' => [
                    'structure' => $input['structure'],
                    'audience' => $input['audience'],
                    'style' => ['tone' => ProductionTemplate::TONES[$input['tone']], 'font' => 'Noto Sans JP', 'theme' => 'light'],
                    'narration' => ['enabled' => true, 'voice' => null],
                    'bgm' => null,
                ],
                'estimated_cost' => 0,
            ]);

            $scenes = ProductionTemplate::scenes($input['structure'], $input['duration'], $input['title'], $input['brief'], $decision->title);

            foreach ($scenes as $scene) {
                $plan->scenes()->create([
                    ...$scene,
                    'track' => ProductionScene::TRACK_VIDEO,
                    'transition' => 'fade',
                    'estimated_cost' => 0,
                    'status' => ProductionScene::STATUS_READY,
                ]);
            }

            return $production;
        });
    }
}
