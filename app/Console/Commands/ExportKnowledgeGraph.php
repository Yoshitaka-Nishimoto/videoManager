<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KnowledgeGraphExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('knowledge:graph
    {--source=graphify-out/graph.json : Graphify が作った graph.json}
    {--out=graphify-out/knowledge/graphify-out/graph.json : 知識を加えた graph.json の出力先}')]
#[Description('Graphify の graph.json に DB の知識と「実装 → コード」の対応を加えて書き出す（graph.html は bin/knowledge-graph で作る）')]
class ExportKnowledgeGraph extends Command
{
    public function handle(KnowledgeGraphExporter $exporter): int
    {
        $source = $this->path($this->option('source'));
        $out = $this->path($this->option('out'));

        if (! is_file($source)) {
            $this->error("{$this->option('source')} がありません。先に WSL で graphify update . を実行してください。");

            return self::FAILURE;
        }

        $graph = json_decode((string) file_get_contents($source), true);

        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) {
            $this->error("{$this->option('source')} を graph.json として読めません。");

            return self::FAILURE;
        }

        $result = $exporter->merge($graph);

        File::ensureDirectoryExists(dirname($out));
        File::put($out, json_encode($result['graph'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("知識のノード {$result['nodes']} 件、エッジ {$result['edges']} 件、実装 → コード {$result['code_links']} 件を加えました：{$this->option('out')}");

        foreach ($result['unresolved'] as $location) {
            $this->warn("graph.json にないため、つなげなかったコード：{$location}（graphify update . で作り直すと解消することがあります）");
        }

        return self::SUCCESS;
    }

    /**
     * 相対パスはプロジェクトのルートから、絶対パスはそのまま使う。
     */
    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
