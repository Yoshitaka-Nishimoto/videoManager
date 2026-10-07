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
        Schema::create('video_productions', function (Blueprint $table) {
            $table->comment('動画制作');

            $table->id()->comment('制作ID');
            $table->foreignId('decision_node_id')->index()->comment('元の設計判断ノード')->constrained('knowledge_nodes')->restrictOnDelete();
            $table->string('title')->comment('動画の仮タイトル');
            $table->text('brief')->nullable()->comment('制作の意図');
            $table->string('ratio', 16)->default('1280:720')->comment('画面の比率（1280:720 / 720:1280 / 960:960）');
            $table->unsignedSmallInteger('target_duration_seconds')->nullable()->comment('目標の長さ（秒）');
            $table->string('status', 16)->default('active')->index()->comment('状態（active / completed / abandoned）');
            $table->foreignId('created_by')->nullable()->comment('作成者')->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_productions');
    }
};
