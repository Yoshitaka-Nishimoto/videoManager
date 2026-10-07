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
        Schema::create('production_renders', function (Blueprint $table) {
            $table->comment('Remotionの書き出し');

            $table->id()->comment('書き出しID');
            $table->foreignId('video_production_id')->index()->comment('制作')->constrained()->restrictOnDelete();
            $table->foreignId('production_plan_id')->index()->comment('書き出した制作案')->constrained()->restrictOnDelete();
            $table->string('kind', 16)->comment('種類（preview / final）');
            $table->string('status', 16)->default('queued')->index()->comment('状態（queued / running / completed / failed）');
            $table->unsignedTinyInteger('progress')->default(0)->comment('進捗率');
            $table->string('storage_path', 1024)->nullable()->comment('保存先パス');
            $table->foreignId('video_id')->nullable()->index()->comment('完成版として登録した動画')->constrained()->nullOnDelete();
            $table->text('error_message')->nullable()->comment('エラー内容');
            $table->foreignId('requested_by')->nullable()->comment('依頼者')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->comment('開始日時');
            $table->timestamp('completed_at')->nullable()->comment('完了日時');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_renders');
    }
};
