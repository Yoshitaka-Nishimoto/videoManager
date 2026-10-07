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
        Schema::create('runway_tasks', function (Blueprint $table) {
            $table->comment('Runwayの生成依頼');

            $table->id()->comment('依頼ID');
            $table->foreignId('production_scene_id')->nullable()->index()->comment('生成する場面')->constrained()->restrictOnDelete();
            $table->string('task_type', 32)->comment('種類（image_to_video / text_to_video / video_to_video / avatar / image / speech / sound）');
            $table->string('model', 64)->comment('モデル');
            $table->text('prompt_text')->nullable()->comment('指示文・台本');
            $table->string('ratio', 16)->nullable()->comment('比率');
            $table->unsignedSmallInteger('duration_seconds')->nullable()->comment('長さ（秒）');
            $table->unsignedBigInteger('seed')->nullable()->comment('乱数の種');
            $table->json('options')->nullable()->comment('追加の指定');
            $table->json('request_payload')->nullable()->comment('実際に送った内容');
            $table->string('status', 16)->default('queued')->index()->comment('状態（queued / running / completed / failed / cancelled）');
            $table->string('runway_task_id', 64)->nullable()->unique()->comment('RunwayのタスクID');
            $table->string('runway_status', 16)->nullable()->comment('Runwayの状態（PENDING / THROTTLED / RUNNING / SUCCEEDED / FAILED / CANCELLED）');
            $table->unsignedTinyInteger('progress')->default(0)->comment('進捗率');
            $table->string('failure_code', 64)->nullable()->comment('失敗コード');
            $table->text('failure_message')->nullable()->comment('失敗の内容');
            $table->decimal('estimated_cost', 10, 4)->nullable()->comment('見積もり費用（米ドル）');
            $table->foreignId('retry_of_id')->nullable()->comment('やり直し元の依頼')->constrained('runway_tasks')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->comment('依頼者')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->comment('Runwayに送った日時');
            $table->timestamp('completed_at')->nullable()->comment('出力の保存まで終わった日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runway_tasks');
    }
};
