<?php

namespace App\Services\Knowledge;

use Symfony\Component\Finder\Finder;

/**
 * リポジトリの実際のファイルから、実装ノードにつなぐコードの場所を探し、確認する（knowledge_tables.md）。
 *
 * コードの正本は Git なので、パスは作業フォルダに実在するものだけを受け付ける。
 * graph.json は古いことがあるため、シンボルの候補はファイルから読み、graph.json はノード ID を補うためだけに使う。
 */
class CodeLocator
{
    /** つなげるファイルを置いてよいフォルダ。 */
    public const ROOTS = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests', 'remotion/src', 'remotion/scripts'];

    private readonly string $basePath;

    /** @var list<string>|null */
    private ?array $files = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $graphNodes = null;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/');
    }

    /**
     * パスの一部から、つなげるファイルの候補を返す。ファイル名に含まれるものを先に並べる。
     *
     * @return list<string>
     */
    public function suggestPaths(string $search, int $limit = 8): array
    {
        $needle = mb_strtolower(trim(str_replace('\\', '/', $search)));

        if ($needle === '') {
            return [];
        }

        $matches = array_filter($this->files(), fn (string $path) => str_contains(mb_strtolower($path), $needle));

        usort($matches, fn (string $a, string $b) => [! str_contains(mb_strtolower(basename($a)), $needle), strlen($a), $a]
            <=> [! str_contains(mb_strtolower(basename($b)), $needle), strlen($b), $b]);

        return array_slice(array_values($matches), 0, $limit);
    }

    /**
     * 入力されたパスをリポジトリからの相対パスにそろえる。つなげない場所なら null。
     */
    public function normalize(string $path): ?string
    {
        $path = ltrim(trim(str_replace('\\', '/', $path)), '/');
        $path = preg_replace('#^(\./)+#', '', $path);

        if ($path === '' || strlen($path) > 255 || in_array('..', explode('/', $path), true)) {
            return null;
        }

        $inRoot = array_filter(self::ROOTS, fn (string $root) => str_starts_with($path, $root.'/'));

        return $inRoot !== [] && is_file($this->basePath.'/'.$path) ? $path : null;
    }

    /**
     * ファイルにあるクラス・メソッド・関数と、その行。PHP は「クラス::メソッド」、TypeScript・JavaScript は関数名。
     *
     * @return list<array{symbol: string, line: int}>
     */
    public function symbolsIn(string $path): array
    {
        $path = $this->normalize($path);

        if ($path === null) {
            return [];
        }

        $lines = file($this->basePath.'/'.$path, FILE_IGNORE_NEW_LINES) ?: [];
        $isPhp = str_ends_with($path, '.php');
        $symbols = [];
        $class = null;

        foreach ($lines as $index => $line) {
            if ($isPhp) {
                if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/', $line, $m)) {
                    $class = $m[1];
                    $symbols[] = ['symbol' => $class, 'line' => $index + 1];
                } elseif (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/', $line, $m)) {
                    $symbols[] = ['symbol' => $class === null ? $m[1] : "{$class}::{$m[1]}", 'line' => $index + 1];
                }
            } elseif (preg_match('/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*(\w+)/', $line, $m)
                || preg_match('/^\s*(?:export\s+)?const\s+(\w+)\s*(?::[^=]+)?=\s*(?:async\s*)?(?:\(|function\b)/', $line, $m)) {
                $symbols[] = ['symbol' => $m[1], 'line' => $index + 1];
            }
        }

        return $symbols;
    }

    /**
     * 入力を確認し、行・コミット・graph.json のノード ID を補って、つなぐ場所にする。
     *
     * @throws KnowledgeRuleViolation
     */
    public function locate(string $path, ?string $symbol = null, ?int $startLine = null, ?int $endLine = null): CodeLocation
    {
        $normalized = $this->normalize($path)
            ?? throw new KnowledgeRuleViolation('ファイル「'.trim($path).'」は見つからないか、つなげない場所にあります（'.implode('・', self::ROOTS).' の中のファイルだけ）。');

        $symbol = trim((string) $symbol) === '' ? null : trim((string) $symbol);

        if ($symbol !== null && $startLine === null) {
            $startLine = collect($this->symbolsIn($normalized))->firstWhere('symbol', $symbol)['line'] ?? null;
        }

        if ($startLine !== null && $endLine !== null && $endLine < $startLine) {
            throw new KnowledgeRuleViolation('終了行は開始行より後にしてください。');
        }

        return new CodeLocation(
            path: $normalized,
            symbol: $symbol,
            startLine: $startLine,
            endLine: $endLine,
            commitSha: $this->currentCommit(),
            graphifyNodeId: $this->graphNodeId($normalized, $symbol),
        );
    }

    /**
     * 今のブランチの先端のコミット。git コマンドを使わず .git から読む（Sail のコンテナにも git が要らない）。
     */
    public function currentCommit(): ?string
    {
        $git = $this->basePath.'/.git';
        $head = is_file("{$git}/HEAD") ? trim((string) file_get_contents("{$git}/HEAD")) : '';

        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $head = is_file("{$git}/{$ref}") ? trim((string) file_get_contents("{$git}/{$ref}")) : '';

            if ($head === '' && is_file("{$git}/packed-refs")) {
                preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) file_get_contents("{$git}/packed-refs"), $m);
                $head = $m[1] ?? '';
            }
        }

        return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null;
    }

    /**
     * origin が GitHub なら、そのリポジトリの URL（例：https://github.com/owner/repo）。
     */
    public function githubRepositoryUrl(): ?string
    {
        $config = $this->basePath.'/.git/config';

        if (! is_file($config) || ! preg_match('#\[remote "origin"\][^\[]*?url\s*=\s*(?:https://github\.com/|git@github\.com:)([\w.-]+/[\w.-]+?)(?:\.git)?\s*$#m', (string) file_get_contents($config), $m)) {
            return null;
        }

        return "https://github.com/{$m[1]}";
    }

    /**
     * graph.json で同じファイル・同じシンボルのノード ID。graph.json がない・古い場合は null。
     */
    private function graphNodeId(string $path, ?string $symbol): ?string
    {
        $nodes = array_filter($this->graphNodes(), fn (array $node) => ($node['source_file'] ?? null) === $path);

        if ($symbol === null) {
            $wanted = [basename($path)];
        } else {
            [$class, $method] = str_contains($symbol, '::') ? explode('::', $symbol, 2) : [null, $symbol];
            $wanted = [".{$method}()", "{$method}()", $method];
        }

        $candidates = array_values(array_filter($nodes, fn (array $node) => in_array($node['label'] ?? null, $wanted, true)));

        // 同じ名前のメソッドが複数のクラスにあるときは、ID にクラス名を含むものを選ぶ。
        if (isset($class) && count($candidates) > 1) {
            $candidates = array_values(array_filter($candidates, fn (array $node) => str_contains((string) $node['id'], strtolower($class)))) ?: $candidates;
        }

        return $candidates[0]['id'] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graphNodes(): array
    {
        if ($this->graphNodes === null) {
            $file = $this->basePath.'/graphify-out/graph.json';
            $graph = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $this->graphNodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        }

        return $this->graphNodes;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        if ($this->files === null) {
            $this->files = [];

            foreach (self::ROOTS as $root) {
                if (! is_dir($this->basePath.'/'.$root)) {
                    continue;
                }

                foreach (Finder::create()->files()->in($this->basePath.'/'.$root)->exclude('node_modules')->ignoreDotFiles(true) as $file) {
                    $this->files[] = $root.'/'.str_replace('\\', '/', $file->getRelativePathname());
                }
            }
        }

        return $this->files;
    }
}
