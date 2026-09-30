<?php

namespace Tests\Feature\CodeAgent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 一時ディレクトリをプロジェクトルートに見立て、実ファイルを汚さずにエージェントの編集を試す。
 */
abstract class CodeAgentTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(config('code_agent.python'))) {
            $this->markTestSkipped('tree-sitter 用の Python が見つかりません: '.config('code_agent.python'));
        }

        $this->sandbox = sys_get_temp_dir().'/code-agent-'.uniqid();
        File::ensureDirectoryExists($this->sandbox.'/app/Models');
        File::ensureDirectoryExists($this->sandbox.'/resources/views');

        config(['code_agent.base_path' => $this->sandbox]);
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox)) {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    protected function sandboxFile(string $path, ?string $contents = null): string
    {
        if ($contents !== null) {
            File::put($this->sandbox.'/'.$path, $contents);
        }

        return File::get($this->sandbox.'/'.$path);
    }
}
