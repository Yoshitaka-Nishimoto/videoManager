<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 利用者ごとの個人のリソース（血液検査の結果など）を、本人だけが見られるようにする。
 *
 * owner_id が空なら全員で共有、値があればその利用者だけが見られる。
 * 利用者を削除しても個人のデータが共有にならないよう、削除は制限する（restrict）。
 */
return new class extends Migration
{
    private const TABLES = [
        'videos' => '持ち主。空なら全員で共有、値があればその利用者だけが見られる（個人のファイルなど）',
        'knowledge_nodes' => '持ち主。個人の情報源から作ったノードは、その利用者だけが見られる。空なら共有',
        'knowledge_edges' => '持ち主。どちらかの端が個人のノードなら、その利用者だけが見られる。空なら共有',
        'knowledge_sources' => '持ち主。個人の情報源の出典は、その利用者だけが見られる。空なら共有',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $comment) {
            Schema::table($table, function (Blueprint $blueprint) use ($comment) {
                $blueprint->foreignId('owner_id')->nullable()->comment($comment)->constrained('users')->restrictOnDelete();
            });
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->string('source_type', 16)->default('youtube')->comment('情報源の種別（youtube / web / file / runway / remotion）。file は利用者がアップロードした個人のファイル')->change();
            $table->string('storage_path', 1024)->nullable()->comment('保存先パス。個人のファイルは local ディスク（storage/app/private）のパス')->change();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('source_type', 16)->default('youtube')->comment('情報源の種別（youtube / web / runway / remotion）')->change();
            $table->string('storage_path', 1024)->nullable()->comment('保存先パス')->change();
        });

        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('owner_id');
            });
        }
    }
};
