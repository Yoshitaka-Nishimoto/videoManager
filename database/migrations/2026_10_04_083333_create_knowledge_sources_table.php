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
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->comment('知識の出典');

            $table->id()->comment('出典ID');
            $table->string('sourceable_type')->comment('根拠を持つ対象の種類（ノード / エッジ）');
            $table->unsignedBigInteger('sourceable_id')->comment('根拠を持つ対象のID');
            $table->foreignId('video_id')->nullable()->index()->comment('元動画')->constrained()->restrictOnDelete();
            $table->foreignId('video_analysis_id')->nullable()->index()->comment('分析の版')->constrained()->restrictOnDelete();
            $table->string('agent_conversation_message_id', 36)->nullable()->index()->comment('会話メッセージ');
            $table->unsignedInteger('start_seconds')->nullable()->comment('動画の該当時刻（開始・秒）');
            $table->unsignedInteger('end_seconds')->nullable()->comment('動画の該当時刻（終了・秒）');
            $table->text('excerpt')->nullable()->comment('引用・抜粋');
            $table->foreignId('created_by')->nullable()->comment('登録者')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->index(['sourceable_type', 'sourceable_id']);
            $table->foreign('agent_conversation_message_id')
                ->references('id')->on('agent_conversation_messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
