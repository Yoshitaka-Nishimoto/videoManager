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
        Schema::table('videos', function (Blueprint $table) {
            $table->comment('動画');

            $table->string('source_type', 16)->default('youtube')->after('id')->index()->comment('動画の種別（youtube / runway / remotion）');
            $table->string('storage_path', 1024)->nullable()->after('url')->comment('保存先パス');
            $table->text('summary')->nullable()->after('description')->comment('要約');
            $table->foreignId('registered_by')->nullable()->after('thumbnail_url')->comment('登録者')->constrained('users')->nullOnDelete();

            // Columns from create_videos_table: comments only, plus youtube_id / url becoming nullable.
            $table->id()->comment('動画ID')->change();
            $table->string('youtube_id', 32)->nullable()->comment('YouTube動画ID')->change();
            $table->string('url', 2048)->nullable()->comment('URL')->change();
            $table->string('title')->comment('タイトル')->change();
            $table->string('channel_title')->nullable()->comment('チャンネル名')->change();
            $table->text('description')->nullable()->comment('説明')->change();
            $table->unsignedInteger('duration_seconds')->nullable()->comment('再生時間（秒）')->change();
            $table->timestamp('published_at')->nullable()->comment('公開日時')->change();
            $table->string('thumbnail_url', 2048)->nullable()->comment('サムネイルURL')->change();
            $table->timestamp('created_at')->nullable()->comment('作成日時')->change();
            $table->timestamp('updated_at')->nullable()->comment('更新日時')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rows without youtube_id or url (runway / remotion) must be removed before rolling back.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn(['source_type', 'storage_path', 'summary']);
            $table->string('youtube_id', 32)->nullable(false)->change();
            $table->string('url', 2048)->nullable(false)->change();
        });
    }
};
