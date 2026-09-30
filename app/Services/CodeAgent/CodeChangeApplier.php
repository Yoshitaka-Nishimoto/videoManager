<?php

namespace App\Services\CodeAgent;

use App\Models\CodeChange;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

class CodeChangeApplier
{
    public function __construct(protected AstValidator $validator) {}

    /**
     * search を replace に置き換えた結果を AST 検証し、妥当な場合のみファイルへ書き込む。
     * search が空文字なら新規ファイル作成として扱う。
     *
     * @throws CodeChangeException
     */
    public function apply(string $path, string $search, string $replace, string $reason, ?string $toolCallId = null): CodeChange
    {
        $path = $this->normalizePath($path);
        $absolute = $this->absolutePath($path);

        $before = File::exists($absolute) ? File::get($absolute) : null;
        $after = $this->buildNewContent($path, $before, $search, $replace);

        $language = AstValidator::languageFor($path);
        $beforeAst = $before === null ? new AstResult(true) : $this->validator->validate($before, $language);
        $afterAst = $this->validator->validate($after, $language);

        $change = new CodeChange([
            'path' => $path,
            'language' => $language,
            'reason' => $reason,
            'diff' => $this->diff($path, $before ?? '', $after),
            'ast_errors' => $afterAst->errors,
            'symbols_added' => array_values(array_diff($afterAst->symbols, $beforeAst->symbols)),
            'symbols_removed' => array_values(array_diff($beforeAst->symbols, $afterAst->symbols)),
            'tool_call_id' => $toolCallId,
        ]);

        if (! $afterAst->ok) {
            $change->status = CodeChange::STATUS_REJECTED;
            $change->save();

            return $change;
        }

        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $after);

        $change->status = CodeChange::STATUS_APPLIED;
        $change->save();

        return $change;
    }

    /**
     * 相対パスに正規化し、許可ディレクトリ外（絶対パス・.. を含む）を拒否する。
     */
    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new CodeChangeException("パスはプロジェクトルートからの相対パスで指定してください: {$path}");
        }

        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== '' && $s !== '.'));

        if (in_array('..', $segments, true)) {
            throw new CodeChangeException("パスに .. は使用できません: {$path}");
        }

        $path = implode('/', $segments);

        if (! str_ends_with($path, '.php')) {
            throw new CodeChangeException("編集できるのは .php / .blade.php ファイルのみです: {$path}");
        }

        $allowed = collect(config('code_agent.allowed_paths'))
            ->contains(fn (string $dir) => Str::startsWith($path, rtrim($dir, '/').'/'));

        if (! $allowed) {
            throw new CodeChangeException(
                "許可されていないパスです: {$path}（許可: ".implode(', ', config('code_agent.allowed_paths')).'）'
            );
        }

        return $path;
    }

    public function absolutePath(string $normalizedPath): string
    {
        return rtrim(config('code_agent.base_path'), '/').'/'.$normalizedPath;
    }

    protected function buildNewContent(string $path, ?string $before, string $search, string $replace): string
    {
        if ($search === '') {
            if ($before !== null) {
                throw new CodeChangeException("{$path} は既に存在します。既存ファイルの編集には search を指定してください。");
            }

            return $replace;
        }

        if ($before === null) {
            throw new CodeChangeException("{$path} が存在しません。新規作成するには search を空にしてください。");
        }

        $count = substr_count($before, $search);

        if ($count !== 1) {
            throw new CodeChangeException(
                $count === 0
                    ? "search に一致する箇所が {$path} にありません。read_code で最新の内容を確認してください。"
                    : "search が {$path} 内で {$count} 箇所に一致します。一意になるよう前後の行を含めてください。"
            );
        }

        return str_replace($search, $replace, $before);
    }

    protected function diff(string $path, string $before, string $after): string
    {
        $builder = new UnifiedDiffOutputBuilder("--- a/{$path}\n+++ b/{$path}\n");

        return (new Differ($builder))->diff($before, $after);
    }
}
