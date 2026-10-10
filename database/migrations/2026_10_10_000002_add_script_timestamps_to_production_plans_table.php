<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 台本の候補作りの進行状況を出すための時刻。依頼したがまだ始まっていない（キューで順番待ち）のか、
 * Gemini が作成中なのかを区別し、経過時間を表示する。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->timestamp('script_requested_at')->nullable()->after('script_error')->comment('台本の候補作りを依頼した日時');
            $table->timestamp('script_started_at')->nullable()->after('script_requested_at')->comment('台本の候補作りが始まった日時（ジョブが動き出した時刻）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->dropColumn(['script_requested_at', 'script_started_at']);
        });
    }
};
