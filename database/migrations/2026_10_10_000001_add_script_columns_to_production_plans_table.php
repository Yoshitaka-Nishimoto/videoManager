<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 制作案の台本（字幕・ナレーション）の候補。Gemini が 3 案を作り、人が 1 つを選んで場面に入れる（段階 2）。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->string('script_status', 16)->nullable()->after('ai_response')->comment('台本の候補の状態（generating / ready / failed）');
            $table->json('script_candidates')->nullable()->after('script_status')->comment('台本の候補（案ごとの名前・概要・場面ごとのナレーションと字幕と見出し）');
            $table->unsignedTinyInteger('script_selected')->nullable()->after('script_candidates')->comment('場面に入れた候補の番号（0 始まり）');
            $table->text('script_error')->nullable()->after('script_selected')->comment('台本の候補作りに失敗したときの内容');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->dropColumn(['script_status', 'script_candidates', 'script_selected', 'script_error']);
        });
    }
};
