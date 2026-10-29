<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公式サイト・GitHub などの Web ページを、動画と同じく「情報源」として videos に登録し、分析できるようにする。
 *
 * Web ページの本文はこちらで取得して AI に渡す。何を読んで分析したかを後から確かめられるよう、本文と取得元を分析の版ごとに残す。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->comment('情報源（動画・Web ページ）');
            $table->string('source_type', 16)->default('youtube')->comment('情報源の種別（youtube / web / runway / remotion）')->change();
            $table->string('channel_title')->nullable()->comment('チャンネル名（Web ページはサイト名）')->change();
        });

        Schema::table('video_analyses', function (Blueprint $table) {
            $table->string('source_url', 2048)->nullable()->after('prompt')->comment('本文の取得元 URL（Web ページ。GitHub は README の API）');
            $table->longText('source_text')->nullable()->after('source_url')->comment('分析に使った本文（Web ページ）');
            $table->timestamp('source_fetched_at')->nullable()->after('source_text')->comment('本文の取得日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_analyses', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'source_text', 'source_fetched_at']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->comment('動画');
            $table->string('source_type', 16)->default('youtube')->comment('動画の種別（youtube / runway / remotion）')->change();
            $table->string('channel_title')->nullable()->comment('チャンネル名')->change();
        });
    }
};
