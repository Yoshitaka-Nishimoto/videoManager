<?php

namespace App\Services\CodeAgent;

final readonly class AstResult
{
    /**
     * @param  list<array{line: int, column: int, kind: string, snippet: string}>  $errors
     * @param  list<string>  $symbols
     */
    public function __construct(
        public bool $ok,
        public array $errors = [],
        public array $symbols = [],
    ) {}

    /**
     * エージェントに返すための人間可読なエラー一覧。
     */
    public function errorSummary(): string
    {
        return collect($this->errors)
            ->map(fn (array $e) => "- line {$e['line']}, col {$e['column']}: {$e['kind']} near `{$e['snippet']}`")
            ->implode("\n");
    }
}
