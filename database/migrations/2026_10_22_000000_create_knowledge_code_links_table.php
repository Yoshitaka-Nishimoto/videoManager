<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 実装ノードとコードの場所の対応（knowledge_tables.md）。
 *
 * コードの正本は Git、構造は Graphify。ここには人が確定した対応だけを置き、Graphify を再生成しても消えないようにする。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_code_links', function (Blueprint $table) {
            $table->comment('実装とコードの対応');

            $table->id()->comment('対応ID');
            $table->foreignId('knowledge_node_id')->comment('実装ノード（node_type = implementation）')->constrained()->restrictOnDelete();
            $table->string('path')->index()->comment('リポジトリからの相対パス');
            $table->string('symbol')->nullable()->comment('クラス・メソッド名（ファイル全体なら空）');
            $table->unsignedInteger('start_line')->nullable()->comment('開始行（目安）');
            $table->unsignedInteger('end_line')->nullable()->comment('終了行（目安）');
            $table->string('commit_sha', 40)->nullable()->comment('対応を記録したときのコミット');
            $table->string('graphify_node_id')->nullable()->comment('graph.json のノードID（移動用。再生成で変わってよい）');
            $table->text('note')->nullable()->comment('補足');
            $table->string('status', 16)->default('candidate')->index()->comment('状態（candidate / confirmed / deprecated）');
            $table->string('proposed_by', 16)->default('human')->comment('提案元（ai / human）');
            $table->foreignId('confirmed_by')->nullable()->comment('確認者')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->comment('確認日時');
            $table->timestamp('missing_since')->nullable()->comment('graph.json で見つからなくなった日時');
            $table->timestamp('checked_at')->nullable()->comment('graph.json と最後に照合した日時');
            $table->foreignId('created_by')->nullable()->comment('登録者')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            // ファイル全体を指す行（symbol が空）も重複させない。
            $table->unique(['knowledge_node_id', 'path', 'symbol'])->nullsNotDistinct();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_code_links');
    }
};
