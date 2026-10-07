-- フェークデータ削除プロシージャ（PostgreSQL）
--
-- 指定した動画（既定: id=21）以外の videos と、それに連なる
-- video_analyses / knowledge_nodes / knowledge_edges / knowledge_sources /
-- knowledge_revisions / ai_usage_logs を物理削除する。
-- 外部キーが restrictOnDelete のため、参照している側から順に消す。
--
-- 登録:
--   ./vendor/bin/sail exec -T pgsql psql -U sail -d videos < database/sql/del_fake.sql
-- 実行:
--   CALL del_fake();              -- 件数の確認だけ（削除しない）
--   CALL del_fake(21, false);     -- 実際に削除する

CREATE OR REPLACE PROCEDURE del_fake(
    p_keep_video_id bigint DEFAULT 21,
    p_dry_run boolean DEFAULT true
)
LANGUAGE plpgsql
AS $$
DECLARE
    v_videos   bigint[];
    v_analyses bigint[];
    v_nodes    bigint[];
    v_edges    bigint[];
    v_count    bigint;
BEGIN
    -- 残す動画が無いと全件削除になるため止める
    IF NOT EXISTS (SELECT 1 FROM videos WHERE id = p_keep_video_id) THEN
        RAISE EXCEPTION '残す動画 id=% が存在しません', p_keep_video_id;
    END IF;

    -- 削除対象を集める
    SELECT coalesce(array_agg(id), '{}') INTO v_videos
    FROM videos WHERE id <> p_keep_video_id;

    SELECT coalesce(array_agg(id), '{}') INTO v_analyses
    FROM video_analyses WHERE video_id = ANY (v_videos);

    SELECT coalesce(array_agg(id), '{}') INTO v_nodes
    FROM knowledge_nodes
    WHERE video_id = ANY (v_videos)
       OR video_analysis_id = ANY (v_analyses);

    SELECT coalesce(array_agg(id), '{}') INTO v_edges
    FROM knowledge_edges
    WHERE source_node_id = ANY (v_nodes)
       OR target_node_id = ANY (v_nodes);

    RAISE NOTICE '対象: videos=%, video_analyses=%, knowledge_nodes=%, knowledge_edges=%',
        cardinality(v_videos), cardinality(v_analyses), cardinality(v_nodes), cardinality(v_edges);

    IF p_dry_run THEN
        RAISE NOTICE 'dry run のため削除していません。削除するには CALL del_fake(%, false);', p_keep_video_id;
        RETURN;
    END IF;

    -- 1. 出典
    DELETE FROM knowledge_sources
    WHERE video_id = ANY (v_videos)
       OR video_analysis_id = ANY (v_analyses)
       OR (sourceable_type = 'App\Models\KnowledgeNode' AND sourceable_id = ANY (v_nodes))
       OR (sourceable_type = 'App\Models\KnowledgeEdge' AND sourceable_id = ANY (v_edges));
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'knowledge_sources: % 件削除', v_count;

    -- 2. 変更履歴
    DELETE FROM knowledge_revisions
    WHERE (revisable_type = 'App\Models\KnowledgeNode' AND revisable_id = ANY (v_nodes))
       OR (revisable_type = 'App\Models\KnowledgeEdge' AND revisable_id = ANY (v_edges));
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'knowledge_revisions: % 件削除', v_count;

    -- 3. エッジ
    DELETE FROM knowledge_edges WHERE id = ANY (v_edges);
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'knowledge_edges: % 件削除', v_count;

    -- 4. ノード（merged_into_id は nullOnDelete）
    DELETE FROM knowledge_nodes WHERE id = ANY (v_nodes);
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'knowledge_nodes: % 件削除', v_count;

    -- 5. AI使用量ログ（分析に紐づくもの）
    DELETE FROM ai_usage_logs
    WHERE usable_type = 'App\Models\VideoAnalysis'
      AND usable_id = ANY (v_analyses);
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'ai_usage_logs: % 件削除', v_count;

    -- 6. 分析
    DELETE FROM video_analyses WHERE id = ANY (v_analyses);
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'video_analyses: % 件削除', v_count;

    -- 7. 動画
    DELETE FROM videos WHERE id = ANY (v_videos);
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'videos: % 件削除', v_count;
END;
$$;
