<?php

namespace App\Services\Knowledge;

use RuntimeException;

/**
 * 知識の規則（許可されたノードの種類、循環の禁止、重複など）に反する操作。メッセージはそのまま画面に表示する。
 */
class KnowledgeRuleViolation extends RuntimeException {}
