<?php

namespace Tests\Feature;

use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Services\Knowledge\KnowledgeGraphExporter;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class KnowledgeGraphExportTest extends TestCase
{
    use RefreshDatabase;

    private const CODE_GRAPH = [
        'directed' => false,
        'nodes' => [
            ['id' => 'app_services_ai_geminimodels', 'label' => 'GeminiModels.php', 'file_type' => 'code', 'source_file' => 'app/Services/Ai/GeminiModels.php'],
            ['id' => 'app_services_ai_geminimodels_geminimodels_attempt', 'label' => '.attempt()', 'file_type' => 'code', 'source_file' => 'app/Services/Ai/GeminiModels.php'],
        ],
        'links' => [
            ['source' => 'app_services_ai_geminimodels', 'target' => 'app_services_ai_geminimodels_geminimodels_attempt', 'relation' => 'contains', 'confidence' => 'EXTRACTED'],
        ],
    ];

    private KnowledgeNode $decision;

    private KnowledgeNode $implementation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeRelationTypeSeeder::class);

        $this->decision = KnowledgeNode::factory()->decision()->confirmed()->create(['title' => 'モデル階層による役割分担と費用削減']);
        $this->implementation = KnowledgeNode::factory()->implementation()->confirmed()->create(['title' => 'AI 呼び出しのモデル選択']);
        KnowledgeEdge::factory()->create([
            'source_node_id' => $this->implementation->id,
            'target_node_id' => $this->decision->id,
            'relation_type_id' => KnowledgeRelationType::where('key', KnowledgeRelationType::KEY_IMPLEMENTS)->value('id'),
            'status' => KnowledgeNode::STATUS_CONFIRMED,
        ]);
    }

    public function test_knowledge_nodes_edges_and_code_links_are_added_to_the_code_graph(): void
    {
        KnowledgeCodeLink::factory()->for($this->implementation, 'node')->create([
            'path' => 'app/Services/Ai/GeminiModels.php',
            'symbol' => 'GeminiModels::attempt',
            'graphify_node_id' => 'app_services_ai_geminimodels_geminimodels_attempt',
            'status' => KnowledgeNode::STATUS_CONFIRMED,
        ]);

        $result = app(KnowledgeGraphExporter::class)->merge(self::CODE_GRAPH);
        $graph = $result['graph'];

        $this->assertSame([2, 1, 1, []], [$result['nodes'], $result['edges'], $result['code_links'], $result['unresolved']]);
        $this->assertCount(4, $graph['nodes']);

        $node = collect($graph['nodes'])->firstWhere('id', "knowledge_{$this->implementation->id}");
        $this->assertSame('[実装] AI 呼び出しのモデル選択', $node['label']);
        $this->assertSame(route('knowledge.show', $this->implementation), $node['source_file']);

        $links = collect($graph['links'])->map(fn (array $link) => [$link['source'], $link['relation'], $link['target'], $link['confidence']])->all();
        $this->assertContains(["knowledge_{$this->implementation->id}", 'knowledge_implements', "knowledge_{$this->decision->id}", 'EXTRACTED'], $links);
        $this->assertContains(["knowledge_{$this->implementation->id}", KnowledgeGraphExporter::CODE_RELATION, 'app_services_ai_geminimodels_geminimodels_attempt', 'EXTRACTED'], $links);
    }

    public function test_a_stale_graph_node_id_falls_back_to_the_same_file_and_symbol(): void
    {
        KnowledgeCodeLink::factory()->for($this->implementation, 'node')->create([
            'path' => 'app/Services/Ai/GeminiModels.php',
            'symbol' => 'GeminiModels::attempt',
            'graphify_node_id' => 'old_id_from_a_previous_graph',
        ]);
        KnowledgeCodeLink::factory()->for($this->implementation, 'node')->wholeFile()->create(['path' => 'app/Services/Ai/GeminiModels.php']);
        KnowledgeCodeLink::factory()->for($this->implementation, 'node')->create(['path' => 'app/Missing.php', 'symbol' => 'Missing::handle']);

        $result = app(KnowledgeGraphExporter::class)->merge(self::CODE_GRAPH);

        $targets = collect($result['graph']['links'])->where('relation', KnowledgeGraphExporter::CODE_RELATION)->pluck('target')->all();
        $this->assertSame(['app_services_ai_geminimodels_geminimodels_attempt', 'app_services_ai_geminimodels'], $targets);
        $this->assertSame(['app/Missing.php Missing::handle'], $result['unresolved']);
    }

    public function test_deprecated_knowledge_is_left_out_and_candidates_are_marked_as_inferred(): void
    {
        $retired = KnowledgeNode::factory()->create(['title' => '廃止した概念', 'status' => KnowledgeNode::STATUS_DEPRECATED]);
        KnowledgeEdge::factory()->create(['source_node_id' => $retired->id, 'target_node_id' => $this->decision->id]);
        KnowledgeNode::factory()->create(['title' => '候補の概念']);
        KnowledgeCodeLink::factory()->for($this->implementation, 'node')->create([
            'path' => 'app/Services/Ai/GeminiModels.php',
            'symbol' => 'GeminiModels::attempt',
            'status' => KnowledgeNode::STATUS_DEPRECATED,
        ]);

        $result = app(KnowledgeGraphExporter::class)->merge(self::CODE_GRAPH);

        $labels = collect($result['graph']['nodes'])->pluck('label');
        $this->assertNotContains('[概念] 廃止した概念', $labels);
        $this->assertContains('[概念] 候補の概念', $labels);
        $this->assertSame([1, 0], [$result['edges'], $result['code_links']]);
    }

    public function test_the_command_writes_the_merged_graph_without_touching_the_source(): void
    {
        $dir = sys_get_temp_dir().'/knowledge-graph-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/graph.json", json_encode(self::CODE_GRAPH));

        try {
            $this->artisan('knowledge:graph', ['--source' => "{$dir}/graph.json", '--out' => "{$dir}/out/graph.json"])
                ->expectsOutputToContain('知識のノード 2 件、エッジ 1 件、実装 → コード 0 件')
                ->assertSuccessful();

            $this->assertSame(self::CODE_GRAPH, json_decode(File::get("{$dir}/graph.json"), true));
            $this->assertCount(4, json_decode(File::get("{$dir}/out/graph.json"), true)['nodes']);

            $this->artisan('knowledge:graph', ['--source' => "{$dir}/missing.json"])->assertFailed();
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
