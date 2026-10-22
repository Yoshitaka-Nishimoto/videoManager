<?php

namespace App\Services\Knowledge;

/**
 * 実装ノードにつなぐコードの場所。CodeLocator が確認・補完したものだけを KnowledgeCurator に渡す。
 */
final readonly class CodeLocation
{
    public function __construct(
        public string $path,
        public ?string $symbol = null,
        public ?int $startLine = null,
        public ?int $endLine = null,
        public ?string $commitSha = null,
        public ?string $graphifyNodeId = null,
    ) {}
}
