<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_nodes', function (Blueprint $table) {
            $table->comment('知識ノード');

            $table->id()->comment('ノードID');
            $table->string('node_type', 32)->index()->comment('ノードの種類（video / analysis / concept / decision / implementation）');
            $table->string('title')->comment('名称');
            $table->text('description')->nullable()->comment('説明');
            $table->string('status', 16)->default('candidate')->index()->comment('状態（candidate / confirmed / deprecated）');
            $table->foreignId('video_id')->nullable()->unique()->comment('対応する動画')->constrained()->restrictOnDelete();
            $table->foreignId('video_analysis_id')->nullable()->unique()->comment('対応する分析')->constrained()->restrictOnDelete();
            $table->foreignId('merged_into_id')->nullable()->comment('統合先ノード')->constrained('knowledge_nodes')->nullOnDelete();
            $table->vector('embedding', 1536)->nullable()->comment('埋め込みベクトル');
            $table->string('proposed_by', 16)->default('human')->comment('提案元（ai / human）');
            $table->foreignId('confirmed_by')->nullable()->comment('確認者')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->comment('確認日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_nodes');
    }
};
