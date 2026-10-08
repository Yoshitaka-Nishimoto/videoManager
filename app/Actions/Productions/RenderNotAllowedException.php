<?php

namespace App\Actions\Productions;

use RuntimeException;

/**
 * 書き出しの条件を満たしていない（場面がない、Runway の生成物が未選択、確認済みでない版の完成版など）。
 */
class RenderNotAllowedException extends RuntimeException {}
