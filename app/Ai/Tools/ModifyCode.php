<?php

namespace App\Ai\Tools;

use App\Services\CodeAgent\AstResult;
use App\Services\CodeAgent\CodeChangeApplier;
use App\Services\CodeAgent\CodeChangeException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ModifyCode implements Tool
{
    public function __construct(protected CodeChangeApplier $applier) {}

    public function name(): string
    {
        return 'modify_code';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return <<<'TXT'
        Modify a PHP or Blade file by replacing an exact snippet (search) with new code (replace).
        `search` must match the current file content exactly once; include surrounding lines to make it unique.
        To create a new file, pass an empty `search` and the whole file as `replace`.
        The result is parsed with tree-sitter BEFORE it is written. If it has syntax errors the file is NOT
        changed and the errors are returned: fix your code and call this tool again.
        TXT;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $change = $this->applier->apply(
                path: $request['path'],
                search: $request['search'] ?? '',
                replace: $request['replace'],
                reason: $request['reason'],
                toolCallId: $request->toolCallId(),
            );
        } catch (CodeChangeException $e) {
            return 'REJECTED: '.$e->getMessage();
        }

        if (! $change->isApplied()) {
            return "REJECTED: tree-sitter found syntax errors, {$change->path} was not modified.\n"
                .(new AstResult(false, $change->ast_errors))->errorSummary()
                ."\nFix the code and call modify_code again.";
        }

        return "APPLIED: {$change->path} (change #{$change->id})\n".$change->diff;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Project-relative path, e.g. app/Models/User.php')->required(),
            'search' => $schema->string()->description('Exact existing code to replace. Empty string to create a new file.')->required(),
            'replace' => $schema->string()->description('New code that replaces `search`.')->required(),
            'reason' => $schema->string()->description('Short explanation of why this change is made (shown in the change history).')->required(),
        ];
    }
}
