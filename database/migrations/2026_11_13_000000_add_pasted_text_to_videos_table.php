<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ログインが必要なページ（ChatGPT・Claude・Gemini などの AI モデルとの会話）や、JavaScript で本文を表示するページは、
 * こちらから本文を取得できない。利用者がページから写した本文を情報源に保存し、分析ではそれを本文として使う。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->longText('pasted_text')->nullable()->after('description')->comment('利用者が貼り付けた本文（Web ページ）。あれば分析で URL から取得せずにこれを使う');
            $table->timestamp('pasted_text_updated_at')->nullable()->after('pasted_text')->comment('本文を貼り付けた日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['pasted_text', 'pasted_text_updated_at']);
        });
    }
};
