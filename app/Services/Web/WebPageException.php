<?php

namespace App\Services\Web;

use RuntimeException;

/**
 * Web ページが見つからない、ログインが必要、対応していない形式（PDF など）で、取得できなかったときの例外。
 */
class WebPageException extends RuntimeException {}
