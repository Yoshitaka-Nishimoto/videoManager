<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AST Validator
    |--------------------------------------------------------------------------
    |
    | tree-sitter を呼び出す Python と解析スクリプト。既定では graphify
    | (uv tool install graphifyy) の仮想環境に同梱の tree_sitter_php を使う。
    |
    */

    'python' => env('CODE_AGENT_PYTHON', getenv('HOME').'/.local/share/uv/tools/graphifyy/bin/python'),

    'script' => base_path('tools/ast_check.py'),

    'timeout' => (int) env('CODE_AGENT_AST_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Writable Paths
    |--------------------------------------------------------------------------
    |
    | エージェントが読み書きできるのは base_path 配下の以下のディレクトリのみ。
    |
    */

    'base_path' => env('CODE_AGENT_BASE_PATH', base_path()),

    'allowed_paths' => [
        'app',
        'resources/views',
    ],

];
