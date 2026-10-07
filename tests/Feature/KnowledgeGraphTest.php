<?php

namespace Tests\Feature;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\KnowledgeSource;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Database\Seeders\KnowledgeGraphSeeder;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_nodes_start_as_candidates(): void
    {
        $node = KnowledgeNode::query()->create([
            'node_type' => KnowledgeNode::TYPE_CONCEPT,
            'title' => '入力画像の品質確認',
        ]);

        $this->assertSame(KnowledgeNode::STATUS_CANDIDATE, $node->refresh()->status);
    }

    public function test_a_video_has_only_one_video_node(): void
    {
        $video = Video::factory()->create();
        KnowledgeNode::factory()->forVideo($video)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        KnowledgeNode::factory()->forVideo($video)->create();
    }

    public function test_embedding_round_trips_as_a_vector(): void
    {
        $embedding = array_fill(0, 1536, 0.25);

        $node = KnowledgeNode::factory()->create(['embedding' => $embedding]);

        $this->assertSame($embedding, $node->refresh()->embedding);
    }

    public function test_edges_connect_nodes_in_one_direction(): void
    {
        $edge = KnowledgeEdge::factory()->create();

        $this->assertTrue($edge->sourceNode->outgoingEdges->contains($edge));
        $this->assertTrue($edge->targetNode->incomingEdges->contains($edge));
        $this->assertTrue($edge->sourceNode->incomingEdges->isEmpty());
    }

    public function test_the_same_relation_cannot_be_added_twice(): void
    {
        $edge = KnowledgeEdge::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        KnowledgeEdge::factory()->create($edge->only(['source_node_id', 'target_node_id', 'relation_type_id']));
    }

    public function test_an_edge_cannot_point_to_its_own_source(): void
    {
        $node = KnowledgeNode::factory()->create();

        $this->expectException(QueryException::class);

        KnowledgeEdge::factory()->create(['source_node_id' => $node->id, 'target_node_id' => $node->id]);
    }

    public function test_a_node_with_edges_cannot_be_deleted(): void
    {
        $edge = KnowledgeEdge::factory()->create();

        $this->expectException(QueryException::class);

        $edge->sourceNode->delete();
    }

    public function test_sources_link_knowledge_back_to_the_video_and_analysis(): void
    {
        $source = KnowledgeSource::factory()->create();
        $node = $source->sourceable;

        $this->assertInstanceOf(KnowledgeNode::class, $node);
        $this->assertTrue($node->sources->first()->is($source));
        $this->assertSame($source->videoAnalysis->video_id, $source->video_id);
    }

    public function test_revisions_record_changes_without_updated_at(): void
    {
        $node = KnowledgeNode::factory()->create();

        $revision = $node->revisions()->create([
            'action' => KnowledgeRevision::ACTION_CONFIRM,
            'before' => ['status' => '候補'],
            'after' => ['status' => '確認済み'],
            'reason' => '動画の 1:02 で説明されている',
        ]);

        $this->assertNotNull($revision->refresh()->created_at);
        $this->assertSame(['status' => '確認済み'], $revision->after);
    }

    public function test_graph_seeder_builds_nodes_from_analyses_without_duplicates(): void
    {
        $analysis = VideoAnalysis::factory()->completed()->create([
            'content' => [
                'concepts' => ['入力画像の品質確認', '類似検索'],
                'key_points' => [['start_seconds' => 62, 'end_seconds' => 98, 'point' => '品質確認の説明']],
            ],
        ]);

        $this->seed(KnowledgeGraphSeeder::class);
        $this->seed(KnowledgeGraphSeeder::class);

        $videoNode = KnowledgeNode::where('video_id', $analysis->video_id)->sole();
        $analysisNode = KnowledgeNode::where('video_analysis_id', $analysis->id)->sole();
        $concept = KnowledgeNode::where('title', '入力画像の品質確認')->sole();

        $this->assertTrue($analysisNode->outgoingEdges()->where('target_node_id', $videoNode->id)->exists());
        $this->assertSame(3, KnowledgeNode::where('node_type', KnowledgeNode::TYPE_CONCEPT)->count());

        $extracted = $concept->outgoingEdges()->where('target_node_id', $analysisNode->id)->sole();
        $source = $extracted->sources()->sole();
        $this->assertSame(62, $source->start_seconds);
        $this->assertSame($analysis->video_id, $source->video_id);
    }

    public function test_relation_type_seeder_is_idempotent(): void
    {
        $this->seed(KnowledgeRelationTypeSeeder::class);
        $this->seed(KnowledgeRelationTypeSeeder::class);

        $this->assertSame(8, KnowledgeRelationType::count());
        $this->assertTrue(KnowledgeRelationType::where('key', 'related_to')->value('allow_cycle'));
        $this->assertFalse(KnowledgeRelationType::where('key', 'prerequisite_of')->value('allow_cycle'));
    }
}
