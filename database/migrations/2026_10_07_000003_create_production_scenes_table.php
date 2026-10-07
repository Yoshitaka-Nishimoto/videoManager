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
        Schema::create('production_scenes', function (Blueprint $table) {
            $table->comment('制作案の場面');

            $table->id()->comment('場面ID');
            $table->foreignId('production_plan_id')->comment('制作案')->constrained()->cascadeOnDelete();
            $table->string('scene_key', 16)->comment('制作案の中の参照名（s1 / m1 など）');
            $table->string('track', 16)->comment('置き場所（video / material）');
            $table->unsignedSmallInteger('position')->comment('順番（trackごと）');
            $table->string('scene_type', 32)->comment('場面の種類キー（remotion.diagram / runway.image_to_video など）');
            $table->string('title')->comment('場面の名前');
            $table->decimal('duration_seconds', 6, 2)->default(0)->comment('長さ（秒）');
            $table->json('content')->nullable()->comment('Remotionの場面の内容');
            $table->text('prompt_text')->nullable()->comment('Runwayの場面の指示文・台本');
            $table->json('generation_options')->nullable()->comment('Runwayの場面の生成の指定');
            $table->text('narration')->nullable()->comment('ナレーションの文言');
            $table->text('caption')->nullable()->comment('字幕');
            $table->string('transition', 16)->nullable()->comment('次の場面への切り替え（fade / slide / wipe / none）');
            $table->decimal('estimated_cost', 10, 4)->nullable()->comment('見積もり費用（米ドル）');
            $table->string('status', 16)->default('pending')->index()->comment('状態（pending / generating / ready / failed）');
            $table->string('preview_path', 1024)->nullable()->comment('確認用の静止画');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['production_plan_id', 'scene_key']);
            $table->unique(['production_plan_id', 'track', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_scenes');
    }
};
