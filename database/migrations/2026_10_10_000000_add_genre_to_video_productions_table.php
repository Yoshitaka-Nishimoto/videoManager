<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 制作の作成の最初の段階で選ぶ「動画のジャンル」。場面の部品の構成の初期値を決めるのに使う。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_productions', function (Blueprint $table) {
            $table->string('genre', 32)->default('other')->after('decision_node_id')->comment('動画のジャンル（ai / video_creation / healthy_longevity / other）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_productions', function (Blueprint $table) {
            $table->dropColumn('genre');
        });
    }
};
