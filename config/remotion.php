<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remotion の書き出し
    |--------------------------------------------------------------------------
    |
    | コンポジション（Production / Scene）はコードそのものなのでテーブルにせず、
    | ここにプロジェクトの場所と、書き出しの種類ごとの既定の設定だけを書く。
    | 詳しくは public_docs/remotion_tables.md を参照。
    |
    */

    // Remotion のプロジェクト（package.json がある場所）。
    'project_path' => env('REMOTION_PROJECT_PATH', base_path('remotion')),

    // 書き出しに使う Node のスクリプト（プロジェクトからの相対パス）。進捗を 1 行ずつ JSON で出力する。
    'render_script' => env('REMOTION_RENDER_SCRIPT', 'scripts/render.mjs'),

    // Node の実行ファイル。
    'node_binary' => env('REMOTION_NODE_BINARY', 'node'),

    // 読み取り専用の Studio（npm run bundle の出力先、プロジェクトからの相対パス）。/remotion-studio で配信する。
    'studio_build_directory' => 'build',

    // 書き出したファイルを保存するディスクとディレクトリ。
    'disk' => env('REMOTION_DISK', 'local'),
    'output_directory' => 'productions/renders',

    // 素材を Remotion に渡すときにコピーする、プロジェクトの public の中の作業用ディレクトリ。
    'public_assets_directory' => 'public/assets',

    // コンポジション共通の設定。長さは場面の秒数の合計から Remotion 側（calculateMetadata）で決める。
    'width' => 1920,
    'height' => 1080,
    'fps' => 30,

    // 書き出しの種類ごとの既定の設定。
    'kinds' => [
        'still' => [
            'composition_id' => 'Scene',
            'image_format' => 'png',
            'scale' => 1,
        ],
        'preview' => [
            'composition_id' => 'Production',
            'codec' => 'h264',
            'image_format' => 'jpeg',
            'scale' => 0.5,
            'crf' => 28,
        ],
        'final' => [
            'composition_id' => 'Production',
            'codec' => 'h264',
            'image_format' => 'jpeg',
            'scale' => 1,
            'crf' => 18,
        ],
    ],

    // 書き出しが終わらないときに打ち切るまでの秒数。
    'timeout' => (int) env('REMOTION_TIMEOUT', 1800),

    // 動画を書き出すときに同時に描くフレーム数。CPU の全コアに負荷をかけると、この PC では
    // WSL が落ちることがあったため、既定は 1（遅くなるが安全）。
    'concurrency' => (int) env('REMOTION_CONCURRENCY', 1),

];
