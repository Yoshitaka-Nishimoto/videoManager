<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 情報源の表紙（静止画）。Windows の静止画のサーバーで remotion/src の remotion.cover を描いて保存する
 * （public_docs/video_covers.md）。作り直すと上書きし、履歴は残さない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('thumbnail_url')->comment('表紙の画像の保存先（config remotion.disk の中のパス）');
            $table->json('cover_content')->nullable()->after('cover_path')->comment('表紙を描いた内容（remotion.cover の content：heading・subheading・label・topics・footer）');
            $table->timestamp('cover_created_at')->nullable()->after('cover_content')->comment('表紙を作った日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'cover_content', 'cover_created_at']);
        });
    }
};
