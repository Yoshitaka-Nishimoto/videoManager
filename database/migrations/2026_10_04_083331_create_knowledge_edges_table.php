<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_edges', function (Blueprint $table) {
            $table->comment('知識エッジ');

            $table->id()->comment('エッジID');
            $table->foreignId('source_node_id')->comment('接続元ノード')->constrained('knowledge_nodes')->restrictOnDelete();
            $table->foreignId('target_node_id')->index()->comment('接続先ノード')->constrained('knowledge_nodes')->restrictOnDelete();
            $table->foreignId('relation_type_id')->index()->comment('関係の種類')->constrained('knowledge_relation_types')->restrictOnDelete();
            $table->string('status', 16)->default('candidate')->index()->comment('状態（candidate / confirmed / deprecated）');
            $table->decimal('confidence', 4, 3)->nullable()->comment('AIの確信度（0〜1）');
            $table->string('proposed_by', 16)->default('human')->comment('提案元（ai / human）');
            $table->foreignId('confirmed_by')->nullable()->comment('確認者')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->comment('確認日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['source_node_id', 'target_node_id', 'relation_type_id']);
        });

        DB::statement('ALTER TABLE knowledge_edges ADD CONSTRAINT knowledge_edges_no_self_loop CHECK (source_node_id <> target_node_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_edges');
    }
};
