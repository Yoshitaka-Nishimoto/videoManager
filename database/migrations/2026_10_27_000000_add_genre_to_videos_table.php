<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 登録した動画のジャンル（選択肢は VideoProduction::GENRES と同じ）。知識一覧を出典の動画のジャンルで絞るのに使う。
 * 既存の動画はジャンルが分からないため、空（未設定）を許す。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('genre', 32)->nullable()->index()->after('description')->comment('動画のジャンル（ai / video_creation / healthy_longevity / investment / other。未設定なら空）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('genre');
        });
    }
};
