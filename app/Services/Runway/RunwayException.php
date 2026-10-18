<?php

namespace App\Services\Runway;

use RuntimeException;

/**
 * Runway API の呼び出しに失敗した、または生成が失敗・時間切れになったときの例外。
 */
class RunwayException extends RuntimeException {}
