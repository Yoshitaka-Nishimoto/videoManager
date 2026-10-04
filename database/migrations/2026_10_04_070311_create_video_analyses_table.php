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
        Schema::create('video_analyses', function (Blueprint $table) {
            $table->comment('動画分析');

            $table->id()->comment('分析ID');
            $table->foreignId('video_id')->comment('対象動画')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->comment('分析の版');
            $table->string('status', 16)->default('queued')->index()->comment('処理状態（queued / running / completed / failed）');
            $table->unsignedTinyInteger('progress')->default(0)->comment('進捗率');
            $table->text('summary')->nullable()->comment('要約');
            $table->json('content')->nullable()->comment('分析内容');
            $table->string('model')->nullable()->comment('使用モデル');
            $table->text('prompt')->nullable()->comment('使用プロンプト');
            $table->text('error_message')->nullable()->comment('エラー内容');
            $table->foreignId('requested_by')->nullable()->comment('依頼者')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->comment('開始日時');
            $table->timestamp('analyzed_at')->nullable()->comment('分析完了日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['video_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_analyses');
    }
};
