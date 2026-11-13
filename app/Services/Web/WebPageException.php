<?php

namespace App\Services\Web;

use RuntimeException;
use Throwable;

/**
 * Web ページが見つからない、ログインが必要、対応していない形式（PDF など）で、取得できなかったときの例外。
 */
class WebPageException extends RuntimeException
{
    /**
     * @param  bool  $loginRequired  ログインが必要で開けなかったか（本文を貼り付ければ登録・分析できる）
     */
    public function __construct(string $message = '', public readonly bool $loginRequired = false, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
