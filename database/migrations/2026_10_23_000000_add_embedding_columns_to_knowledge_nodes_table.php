<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 埋め込み（embedding）を作り直す目印と、近いノードを速く探す索引（knowledge_tables.md）。
 *
 * HNSW 索引は vector 型では 2000 次元までなので、埋め込みは 1536 次元で作る。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_nodes', function (Blueprint $table) {
            $table->string('embedding_model', 64)->nullable()->after('embedding')->comment('埋め込みを作ったモデル');
            $table->string('embedding_hash', 64)->nullable()->after('embedding_model')->comment('埋め込みの元にした文のハッシュ（変わったら作り直す）');
            $table->timestamp('embedded_at')->nullable()->after('embedding_hash')->comment('埋め込みを作った日時');
        });

        DB::statement('CREATE INDEX knowledge_nodes_embedding_hnsw_index ON knowledge_nodes USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS knowledge_nodes_embedding_hnsw_index');

        Schema::table('knowledge_nodes', function (Blueprint $table) {
            $table->dropColumn(['embedding_model', 'embedding_hash', 'embedded_at']);
        });
    }
};
