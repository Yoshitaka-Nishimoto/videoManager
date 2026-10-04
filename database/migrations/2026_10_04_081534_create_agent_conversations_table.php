<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::create($conversationsTable, function (Blueprint $table) {
            $table->comment('AI会話');

            $table->string('id', 36)->primary()->comment('会話ID');
            $table->string('participant_type')->nullable()->comment('参加者の種類');
            $table->unsignedBigInteger('participant_id')->nullable()->comment('参加者ID');
            $table->string('title')->comment('会話タイトル');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::create($messagesTable, function (Blueprint $table) {
            $table->comment('AI会話メッセージ');

            $table->string('id', 36)->primary()->comment('メッセージID');
            $table->string('conversation_id', 36)->index()->comment('会話');
            $table->string('participant_type')->nullable()->comment('参加者の種類');
            $table->unsignedBigInteger('participant_id')->nullable()->comment('参加者ID');
            $table->string('agent')->comment('エージェント');
            $table->string('role', 25)->comment('発言者（user / assistant / tool）');
            $table->text('content')->comment('本文');
            $table->text('attachments')->comment('添付ファイル');
            $table->longText('steps')->comment('処理ステップ');
            $table->text('usage')->comment('使用量');
            $table->text('meta')->comment('付加情報');
            $table->string('status', 25)->comment('状態');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('ai.conversations.tables.messages', 'agent_conversation_messages'));
        Schema::dropIfExists(config('ai.conversations.tables.conversations', 'agent_conversations'));
    }
};
