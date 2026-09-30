<?php

namespace App\Services\CodeAgent;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class AstValidator
{
    /**
     * ファイル種別に応じて tree-sitter で構文検証する。
     */
    public function validate(string $source, string $language): AstResult
    {
        if ($language === 'blade') {
            try {
                // Blade ディレクティブを PHP に展開してから解析する（@if/@endif の対応崩れなどを検出できる）
                $source = Blade::compileString($source);
            } catch (Throwable $e) {
                return new AstResult(false, [[
                    'line' => 0, 'column' => 0, 'kind' => 'blade compile error', 'snippet' => $e->getMessage(),
                ]]);
            }
        }

        return $this->parse($source);
    }

    public static function languageFor(string $path): string
    {
        return str_ends_with($path, '.blade.php') ? 'blade' : 'php';
    }

    protected function parse(string $source): AstResult
    {
        $result = Process::timeout(config('code_agent.timeout'))
            ->input(json_encode(['source' => $source, 'mode' => 'php']))
            ->run([config('code_agent.python'), config('code_agent.script')]);

        if ($result->failed()) {
            throw new RuntimeException('tree-sitter の実行に失敗しました: '.trim($result->errorOutput()));
        }

        $data = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return new AstResult($data['ok'], $data['errors'], $data['symbols']);
    }
}
