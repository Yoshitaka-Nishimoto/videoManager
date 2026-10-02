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
       PHPやBladeファイルを修正して、特定のスニペット（search）を新しいコード（replace）に置き換えます。
       `search`は現在のファイル内容と正確に1回だけ一致する必要があります；
       一意にするために周囲の行も含めてください。
       新しいファイルを作る場合は、空の`search`を渡し、ファイル全体を`replace`にしてください。
       結果は書き込む前にtree-sitterで解析されます。構文エラーがある場合、
       ファイルは変更されずエラーが返されます：コードを修正して、このツールをもう一度呼び出してください。
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
