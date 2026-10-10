<?php

namespace App\Actions\Productions;

use RuntimeException;

/**
 * 台本を作り直せない・場面に入れられない（確認済みの版、候補を作成中、候補の番号がないなど）。
 */
class ScriptNotAllowedException extends RuntimeException {}
