<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\Video;
use App\Models\VideoAnalysis;
use App\Services\Analysis\AnalysisResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 動画・分析の事実をノードとして記録し、AI が抽出した概念をノードとエッジの「候補」として出典付きで登録する。
 *
 * 候補の採用・修正・統合は人が知識画面で行う。ここでは確定させない。
 */
class KnowledgeCandidateWriter
{
    /**
     * 動画ノードを用意する。同じ動画のノードは一つだけ。
     */
    public function videoNode(Video $video): KnowledgeNode
    {
        $node = KnowledgeNode::query()->firstOrCreate(['video_id' => $video->id], [
            'node_type' => KnowledgeNode::TYPE_VIDEO,
            'title' => $video->title,
            'description' => $video->summary,
            'status' => KnowledgeNode::STATUS_CONFIRMED,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
            'confirmed_by' => $video->registered_by,
            'confirmed_at' => now(),
        ]);

        if ($node->wasRecentlyCreated) {
            $this->recordRevision($node, KnowledgeRevision::ACTION_CREATE, '動画の登録', $video->registered_by);
        }

        return $node;
    }

    /**
     * 分析ノードと、分析から抽出した概念の候補を登録する。
     */
    public function writeAnalysis(VideoAnalysis $analysis, AnalysisResult $result): void
    {
        DB::transaction(function () use ($analysis, $result) {
            $video = $analysis->video;
            $videoNode = $this->videoNode($video);

            $analysisNode = KnowledgeNode::query()->firstOrCreate(['video_analysis_id' => $analysis->id], [
                'node_type' => KnowledgeNode::TYPE_ANALYSIS,
                'title' => "「{$video->title}」の分析（第{$analysis->version}版）",
                'description' => $result->summary,
                'status' => KnowledgeNode::STATUS_CONFIRMED,
                'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
                'confirmed_by' => $analysis->requested_by,
                'confirmed_at' => now(),
            ]);

            $this->edge($analysisNode, 'analyzes', $videoNode, [
                'status' => KnowledgeNode::STATUS_CONFIRMED,
                'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
                'confirmed_by' => $analysis->requested_by,
                'confirmed_at' => now(),
            ]);

            foreach ($result->concepts as $concept) {
                $conceptNode = $this->conceptNode($concept['title'], $concept['description']);

                $edge = $this->edge($conceptNode, 'extracted_from', $analysisNode, [
                    'status' => KnowledgeNode::STATUS_CANDIDATE,
                    'confidence' => $concept['confidence'],
                    'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
                ]);

                $point = $result->keyPoints[$concept['key_point'] ?? -1] ?? null;

                if ($edge->wasRecentlyCreated) {
                    $edge->sources()->create([
                        'video_id' => $video->id,
                        'video_analysis_id' => $analysis->id,
                        'start_seconds' => $point['start_seconds'] ?? null,
                        'end_seconds' => $point['end_seconds'] ?? null,
                        'excerpt' => $point['point'] ?? null,
                    ]);
                }
            }
        });
    }

    /**
     * 同名の概念があれば再利用し（統合済みなら統合先）、なければ AI 提案の候補として作る。
     */
    private function conceptNode(string $title, string $description): KnowledgeNode
    {
        $sameTitle = KnowledgeNode::query()
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->where('title', $title)
            ->orderBy('id')
            ->get();

        $existing = $sameTitle->firstWhere(fn (KnowledgeNode $node) => $node->status !== KnowledgeNode::STATUS_DEPRECATED)
            ?? $sameTitle->firstWhere(fn (KnowledgeNode $node) => $node->merged_into_id !== null)?->mergedInto;

        if ($existing !== null) {
            return $existing;
        }

        $node = KnowledgeNode::query()->create([
            'node_type' => KnowledgeNode::TYPE_CONCEPT,
            'title' => $title,
            'description' => $description,
            'status' => KnowledgeNode::STATUS_CANDIDATE,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
        ]);

        $this->recordRevision($node, KnowledgeRevision::ACTION_CREATE, 'AI が分析から抽出', changedBy: null);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function edge(KnowledgeNode $source, string $relation, KnowledgeNode $target, array $attributes): KnowledgeEdge
    {
        $relationType = KnowledgeRelationType::query()->where('key', $relation)->first()
            ?? throw new RuntimeException("関係の種類「{$relation}」がありません。KnowledgeRelationTypeSeeder を実行してください。");

        return KnowledgeEdge::query()->firstOrCreate([
            'source_node_id' => $source->id,
            'target_node_id' => $target->id,
            'relation_type_id' => $relationType->id,
        ], $attributes);
    }

    private function recordRevision(KnowledgeNode $node, string $action, string $reason, ?int $changedBy): void
    {
        $node->revisions()->create([
            'action' => $action,
            'after' => $node->only(['node_type', 'title', 'status']),
            'reason' => $reason,
            'changed_by' => $changedBy,
        ]);
    }
}
