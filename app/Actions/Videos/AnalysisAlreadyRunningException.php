<?php

namespace App\Actions\Videos;

use RuntimeException;

/**
 * 同じ動画の分析が待機中または実行中のときに、新しい分析を依頼しようとした。
 */
class AnalysisAlreadyRunningException extends RuntimeException {}
