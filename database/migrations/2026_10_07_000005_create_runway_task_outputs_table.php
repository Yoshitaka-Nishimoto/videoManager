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
        Schema::create('runway_task_outputs', function (Blueprint $table) {
            $table->comment('Runwayの生成物');

            $table->id()->comment('出力ID');
            $table->foreignId('runway_task_id')->comment('依頼')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0)->comment('出力の順番');
            $table->string('media_type', 16)->comment('種類（video / image / audio）');
            $table->string('storage_path', 1024)->comment('保存先パス');
            $table->string('mime_type', 64)->nullable()->comment('MIMEタイプ');
            $table->unsignedBigInteger('size_bytes')->nullable()->comment('ファイルサイズ');
            $table->unsignedSmallInteger('width')->nullable()->comment('横の画素数');
            $table->unsignedSmallInteger('height')->nullable()->comment('縦の画素数');
            $table->decimal('duration_seconds', 8, 3)->nullable()->comment('長さ（秒）');
            $table->foreignId('video_id')->nullable()->index()->comment('動画として登録した場合の動画')->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->timestamp('updated_at')->nullable()->comment('更新日時');

            $table->unique(['runway_task_id', 'position']);
        });

        // 場面と生成物は互いに参照するため、場面側の列は生成物のテーブルを作った後に追加する。
        Schema::table('production_scenes', function (Blueprint $table) {
            $table->foreignId('selected_output_id')->nullable()->after('status')->comment('採用した生成物')->constrained('runway_task_outputs')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_scenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_output_id');
        });

        Schema::dropIfExists('runway_task_outputs');
    }
};
