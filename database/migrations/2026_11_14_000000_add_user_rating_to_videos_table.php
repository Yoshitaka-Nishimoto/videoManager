<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 情報源のユーザ評価（0〜100）。情報源一覧のカードで入力し、評価の高い順に並べるのに使う。
 * ジャンルと同じく情報源ごとに一つ（利用者ごとには分けない）。未評価は空。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedTinyInteger('user_rating')->nullable()->index()->after('genre')->comment('ユーザ評価（0〜100。未評価なら空）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('user_rating');
        });
    }
};
