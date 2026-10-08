<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube Data API の statistics（再生回数・高評価・コメント数）を videos に持つ。
 * 値は時間とともに変わるため、取得日時も残す。投稿者が非公開にしている値は空のまま。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedBigInteger('view_count')->nullable()->after('thumbnail_url')->comment('再生回数');
            $table->unsignedBigInteger('like_count')->nullable()->after('view_count')->comment('高評価の数（非公開なら空）');
            $table->unsignedBigInteger('comment_count')->nullable()->after('like_count')->comment('コメント数（無効なら空）');
            $table->timestamp('statistics_fetched_at')->nullable()->after('comment_count')->comment('統計の取得日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['view_count', 'like_count', 'comment_count', 'statistics_fetched_at']);
        });
    }
};
