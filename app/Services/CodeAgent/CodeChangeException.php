<?php

namespace App\Services\CodeAgent;

use RuntimeException;

/**
 * パス違反や置換対象が見つからないなど、AST 検証以前に弾かれた変更。
 */
class CodeChangeException extends RuntimeException {}
