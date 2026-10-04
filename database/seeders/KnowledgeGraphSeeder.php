<?php

namespace Database\Seeders;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Laravel\Ai\Models\ConversationMessage;

class KnowledgeGraphSeeder extends Seeder
{
    /**
     * A near-duplicate of 「入力画像の品質確認」 left for the merge workflow to find.
     */
    private const DUPLICATE_CONCEPT = '画像品質確認';

    private ?User $user;

    /** @var Collection<string, KnowledgeRelationType> */
    private Collection $relationTypes;

    /**
     * Build video, analysis and concept nodes from existing videos, analyses and conversations.
     */
    public function run(): void
    {
        $this->call(KnowledgeRelationTypeSeeder::class);

        $this->user = User::query()->first();
        $this->relationTypes = KnowledgeRelationType::query()->get()->keyBy('key');

        Video::query()
            ->with(['analyses' => fn ($query) => $query->where('status', VideoAnalysis::STATUS_COMPLETED)->orderBy('version')])
            ->orderBy('id')
            ->each(function (Video $video) {
                $videoNode = $this->videoNode($video);

                foreach ($video->analyses as $analysis) {
                    $analysisNode = $this->analysisNode($analysis);
                    $this->connect($analysisNode, 'analyzes', $videoNode, confirmed: true);

                    $this->seedConcepts($analysis, $analysisNode);
                }
            });

        $this->seedConversationSources();
        $this->seedDuplicateConcept();
    }

    private function videoNode(Video $video): KnowledgeNode
    {
        return KnowledgeNode::query()->firstOrCreate(['video_id' => $video->id], [
            'node_type' => KnowledgeNode::TYPE_VIDEO,
            'title' => $video->title,
            'description' => $video->summary,
            'status' => KnowledgeNode::STATUS_CONFIRMED,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
            'confirmed_by' => $this->user?->id,
            'confirmed_at' => $video->created_at,
        ]);
    }

    private function analysisNode(VideoAnalysis $analysis): KnowledgeNode
    {
        return KnowledgeNode::query()->firstOrCreate(['video_analysis_id' => $analysis->id], [
            'node_type' => KnowledgeNode::TYPE_ANALYSIS,
            'title' => "「{$analysis->video->title}」の分析（第{$analysis->version}版）",
            'description' => $analysis->summary,
            'status' => KnowledgeNode::STATUS_CONFIRMED,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
            'confirmed_by' => $this->user?->id,
            'confirmed_at' => $analysis->analyzed_at,
        ]);
    }

    /**
     * Turn each analysed concept into a node extracted from the analysis, with the video moment as its source.
     */
    private function seedConcepts(VideoAnalysis $analysis, KnowledgeNode $analysisNode): void
    {
        $keyPoints = $analysis->content['key_points'] ?? [];
        $conceptNodes = collect($analysis->content['concepts'] ?? [])
            ->unique()
            ->map(fn (string $title) => $this->conceptNode($title));

        foreach ($conceptNodes as $conceptNode) {
            $edge = $this->connect($conceptNode, 'extracted_from', $analysisNode);

            if ($edge->wasRecentlyCreated && $keyPoints !== []) {
                $point = fake()->randomElement($keyPoints);

                $edge->sources()->create([
                    'video_id' => $analysis->video_id,
                    'video_analysis_id' => $analysis->id,
                    'start_seconds' => $point['start_seconds'],
                    'end_seconds' => $point['end_seconds'],
                    'excerpt' => $point['point'],
                ]);
            }
        }

        // Concepts found in the same analysis are loosely related; link each pair once, lower id first.
        $conceptNodes->sortBy('id')->values()->each(function (KnowledgeNode $node, int $index) use ($conceptNodes) {
            $conceptNodes->sortBy('id')->values()->slice($index + 1)
                ->each(fn (KnowledgeNode $other) => $this->connect($node, 'related_to', $other));
        });
    }

    private function conceptNode(string $title): KnowledgeNode
    {
        $confirmed = fake()->boolean(30);

        $node = KnowledgeNode::query()->firstOrCreate([
            'node_type' => KnowledgeNode::TYPE_CONCEPT,
            'title' => $title,
        ], [
            'description' => "動画の分析から抽出された概念「{$title}」。",
            'status' => $confirmed ? KnowledgeNode::STATUS_CONFIRMED : KnowledgeNode::STATUS_CANDIDATE,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
            'confirmed_by' => $confirmed ? $this->user?->id : null,
            'confirmed_at' => $confirmed ? now() : null,
        ]);

        if ($node->wasRecentlyCreated) {
            $this->recordRevision($node, KnowledgeRevision::ACTION_CREATE, null, ['status' => KnowledgeNode::STATUS_CANDIDATE], 'AIが分析から抽出', changedBy: null);

            if ($confirmed) {
                $this->recordRevision($node, KnowledgeRevision::ACTION_CONFIRM, ['status' => KnowledgeNode::STATUS_CANDIDATE], ['status' => KnowledgeNode::STATUS_CONFIRMED], '動画の該当箇所で内容を確認した', changedBy: $this->user?->id);
            }
        }

        return $node;
    }

    private function connect(KnowledgeNode $source, string $relation, KnowledgeNode $target, bool $confirmed = false): KnowledgeEdge
    {
        return KnowledgeEdge::query()->firstOrCreate([
            'source_node_id' => $source->id,
            'target_node_id' => $target->id,
            'relation_type_id' => $this->relationTypes[$relation]->id,
        ], [
            'status' => $confirmed ? KnowledgeNode::STATUS_CONFIRMED : KnowledgeNode::STATUS_CANDIDATE,
            'confidence' => $confirmed ? null : fake()->randomFloat(3, 0.55, 0.98),
            'proposed_by' => $confirmed ? KnowledgeNode::PROPOSED_BY_HUMAN : KnowledgeNode::PROPOSED_BY_AI,
            'confirmed_by' => $confirmed ? $this->user?->id : null,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    /**
     * Point concept nodes at the conversation messages that asked about them.
     */
    private function seedConversationSources(): void
    {
        $concepts = KnowledgeNode::query()->where('node_type', KnowledgeNode::TYPE_CONCEPT)->get();

        ConversationMessage::query()->where('role', 'user')->each(function (ConversationMessage $message) use ($concepts) {
            $concepts
                ->filter(fn (KnowledgeNode $concept) => str_contains($message->content, "「{$concept->title}」"))
                ->each(fn (KnowledgeNode $concept) => $concept->sources()->firstOrCreate(
                    ['agent_conversation_message_id' => $message->id],
                    ['excerpt' => $message->content, 'created_by' => $this->user?->id],
                ));
        });
    }

    /**
     * Add a candidate concept that duplicates an existing one, for testing the merge workflow.
     */
    private function seedDuplicateConcept(): void
    {
        $original = KnowledgeNode::query()
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->where('title', '入力画像の品質確認')
            ->first();

        $analysisNode = $original?->outgoingEdges()
            ->where('relation_type_id', $this->relationTypes['extracted_from']->id)
            ->first()
            ?->targetNode;

        if ($analysisNode === null) {
            return;
        }

        $duplicate = KnowledgeNode::query()->firstOrCreate([
            'node_type' => KnowledgeNode::TYPE_CONCEPT,
            'title' => self::DUPLICATE_CONCEPT,
        ], [
            'description' => '「入力画像の品質確認」と同じ概念の可能性がある候補。',
            'status' => KnowledgeNode::STATUS_CANDIDATE,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
        ]);

        $this->connect($duplicate, 'extracted_from', $analysisNode);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordRevision(KnowledgeNode $node, string $action, ?array $before, ?array $after, string $reason, ?int $changedBy): void
    {
        $node->revisions()->create([
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'changed_by' => $changedBy,
        ]);
    }
}
