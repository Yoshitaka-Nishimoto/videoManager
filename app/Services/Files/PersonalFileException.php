<?php

namespace App\Services\Files;

use RuntimeException;

/**
 * 個人のファイルを読めないとき。メッセージはそのまま画面に出す。
 */
class PersonalFileException extends RuntimeException {}
