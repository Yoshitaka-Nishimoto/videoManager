<?php

namespace App\Ai\Tools;

use App\Services\CodeAgent\CodeChangeApplier;
use App\Services\CodeAgent\CodeChangeException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadCode implements Tool
{
    public function __construct(protected CodeChangeApplier $applier) {}

    public function name(): string
    {
        return 'read_code';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Read a PHP or Blade file from the project (app/ or resources/views/). Returns the raw file content. Always read a file before modifying it.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $path = $this->applier->normalizePath($request['path']);
        } catch (CodeChangeException $e) {
            return 'ERROR: '.$e->getMessage();
        }

        $absolute = $this->applier->absolutePath($path);

        return File::exists($absolute)
            ? File::get($absolute)
            : "ERROR: {$path} は存在しません。";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Project-relative path, e.g. app/Models/User.php')->required(),
        ];
    }
}
