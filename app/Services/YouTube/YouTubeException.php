<?php

namespace App\Services\YouTube;

use RuntimeException;

/**
 * 動画が見つからない、API の呼び出しに失敗したなど、動画情報を取得できなかったときの例外。
 */
class YouTubeException extends RuntimeException {}
