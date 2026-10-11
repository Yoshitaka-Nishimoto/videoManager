<?php

namespace App\Actions\Productions;

use RuntimeException;

/**
 * 使っている素材を削除しようとしたときの例外。メッセージは画面にそのまま表示する。
 */
class AssetInUseException extends RuntimeException {}
