<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ページから写した本文には、画像が「xxxxx.png」のようなファイル名（画像の alt）だけで残る。
 * 本文を表示するときに画像を出せるよう、元のページから取った「ファイル名 → 画像の URL」の対応を残す。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->json('pasted_images')->nullable()->after('pasted_text_updated_at')->comment('貼り付けた本文に出てくる画像の名前（alt・ファイル名）と、元のページの画像の URL の対応');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('pasted_images');
        });
    }
};
