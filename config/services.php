<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    // Runway API（画像・動画の生成）。キーは developer.runwayml.com で発行し、クレジットを購入しておく。
    'runway' => [
        'key' => env('RUNWAY_API_KEY'),
        'base_url' => env('RUNWAY_BASE_URL', 'https://api.dev.runwayml.com/v1'),
        'version' => env('RUNWAY_API_VERSION', '2024-11-06'),
        // 生成の完了を待つ上限（秒）と、状態を確かめる間隔（秒）。
        'wait_seconds' => (int) env('RUNWAY_WAIT_SECONDS', 300),
        'poll_seconds' => (int) env('RUNWAY_POLL_SECONDS', 5),
    ],

    // 動画分析に使う実装（dummy / gemini）と、Gemini のモデル（空なら SDK の既定）。
    // 混雑・回数制限のときは、代わりのモデル（カンマ区切り）を順に試す。
    'video_analyzer' => [
        'driver' => env('VIDEO_ANALYZER', 'dummy'),
        'gemini_model' => env('GEMINI_ANALYSIS_MODEL'),
        'gemini_fallback_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_ANALYSIS_FALLBACK_MODELS', 'gemini-3.5-flash,gemini-3.7-flash,gemini-3.8-flash'))))),
        // 文章だけを渡す分析（Web ページ・個人のファイル）と台本の候補に使う AI（gemini / openai）。
        // YouTube の動画を URL のまま見られるのは Gemini だけなので、動画の分析はこの設定によらず Gemini。
        'text_provider' => env('TEXT_AI_PROVIDER', 'gemini'),
        // text_provider が openai のときのモデル（空なら SDK の既定）。
        'openai_model' => env('OPENAI_ANALYSIS_MODEL'),
    ],

    // 台本の候補を作る実装（dummy / gemini）。指定がなければ動画分析と同じ。AI とモデルは上の設定を使う。
    'script_writer' => [
        'driver' => env('SCRIPT_WRITER', env('VIDEO_ANALYZER', 'dummy')),
    ],

    // 知識ノードの埋め込み（pgvector の検索・重複の発見に使う）。テストでは false にして AI を呼ばない。
    // 次元数は knowledge_nodes.embedding（vector(1536)）と HNSW 索引の上限（2000）に合わせる。
    'knowledge_embeddings' => [
        'enabled' => (bool) env('KNOWLEDGE_EMBEDDINGS', true),
        'provider' => env('KNOWLEDGE_EMBEDDING_PROVIDER', 'gemini'),
        'model' => env('KNOWLEDGE_EMBEDDING_MODEL', 'gemini-embedding-2'),
        'dimensions' => 1536,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
