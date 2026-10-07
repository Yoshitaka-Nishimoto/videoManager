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
        Schema::create('production_plans', function (Blueprint $table) {
            $table->comment('制作案');

            $table->id()->comment('制作案ID');
            $table->foreignId('video_production_id')->comment('制作')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->comment('版');
            $table->string('status', 16)->default('generating')->index()->comment('状態（generating / failed / candidate / confirmed / deprecated）');
            $table->string('proposed_by', 16)->default('ai')->comment('提案元（ai / human）');
            $table->foreignId('based_on_id')->nullable()->comment('修正元の版')->constrained('production_plans')->nullOnDelete();
            $table->text('summary')->nullable()->comment('構成の概要');
            $table->json('settings')->nullable()->comment('全体の設定（style / narration / bgm）');
            $table->json('mock_asset_ids')->nullable()->comment('Geminiに渡したモック図の素材ID');
            $table->string('model')->nullable()->comment('使用モデル');
            $table->text('prompt')->nullable()->comment('使用プロンプト');
            $table->json('ai_response')->nullable()->comment('AIの応答');
            $table->decimal('estimated_cost', 10, 4)->nullable()->comment('Runwayの見積もり費用の合計（米ドル）');
            $table->text('error_message')->nullable()->comment('エラー内容');
            $table->foreignId('confirmed_by')->nullable()->comment('採用した人')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->comment('採用日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['video_production_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_plans');
    }
};
