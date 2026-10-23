<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 動画ノードは動画の登録時（分析の前）に作られ、説明が空のままだったので、動画の要約で埋める。
 * 以後は分析のたびに KnowledgeCandidateWriter が更新する。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE knowledge_nodes AS n
            SET description = v.summary
            FROM videos AS v
            WHERE n.video_id = v.id
              AND n.node_type = 'video'
              AND (n.description IS NULL OR n.description = '')
              AND v.summary IS NOT NULL
              AND v.summary <> ''
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 説明を空に戻すと情報が失われるだけなので、戻さない。
    }
};
