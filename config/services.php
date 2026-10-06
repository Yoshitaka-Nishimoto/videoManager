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

    // 動画分析に使う実装（dummy / gemini）と、Gemini のモデル（空なら SDK の既定）。
    // 混雑・回数制限のときは、代わりのモデル（カンマ区切り）を順に試す。
    'video_analyzer' => [
        'driver' => env('VIDEO_ANALYZER', 'dummy'),
        'gemini_model' => env('GEMINI_ANALYSIS_MODEL'),
        'gemini_fallback_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_ANALYSIS_FALLBACK_MODELS', 'gemini-3.5-flash,gemini-2.5-flash'))))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
