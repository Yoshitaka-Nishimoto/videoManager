<?php

namespace Tests\Feature\CodeAgent;

use App\Ai\Agents\CodeFixer;
use App\Ai\Tools\ModifyCode;
use App\Ai\Tools\ReadCode;
use Laravel\Ai\Tools\Request;

class ModifyCodeToolTest extends CodeAgentTestCase
{
    public function test_tool_reports_syntax_errors_so_the_agent_can_self_correct(): void
    {
        $this->sandboxFile('app/Models/Video.php', "<?php\n\nclass Video\n{\n}\n");

        // 1回目: 壊れたコード → REJECTED とエラー位置が返る
        $first = (string) app(ModifyCode::class)->handle(new Request([
            'path' => 'app/Models/Video.php',
            'search' => "{\n}",
            'replace' => "{\n    public function play() {\n}",
            'reason' => 'play() を追加',
        ]));

        $this->assertStringStartsWith('REJECTED: tree-sitter found syntax errors', $first);
        $this->assertStringContainsString('line ', $first);

        // 2回目: 修正したコード → APPLIED と diff が返る
        $second = (string) app(ModifyCode::class)->handle(new Request([
            'path' => 'app/Models/Video.php',
            'search' => "{\n}",
            'replace' => "{\n    public function play(): void {}\n}",
            'reason' => 'play() を追加',
        ]));

        $this->assertStringStartsWith('APPLIED: app/Models/Video.php', $second);
        $this->assertStringContainsString('+    public function play(): void {}', $second);
        $this->assertDatabaseCount('code_changes', 2);
    }

    public function test_tool_reports_path_violations_without_logging(): void
    {
        $result = (string) app(ModifyCode::class)->handle(new Request([
            'path' => '../outside.php',
            'search' => '',
            'replace' => "<?php\n",
            'reason' => 'escape',
        ]));

        $this->assertStringStartsWith('REJECTED:', $result);
        $this->assertDatabaseCount('code_changes', 0);
    }

    public function test_read_code_returns_file_content(): void
    {
        $this->sandboxFile('app/Models/Video.php', "<?php\n\nclass Video {}\n");

        $this->assertSame(
            "<?php\n\nclass Video {}\n",
            (string) app(ReadCode::class)->handle(new Request(['path' => 'app/Models/Video.php'])),
        );
    }

    public function test_agent_exposes_both_tools(): void
    {
        $names = collect(CodeFixer::make()->tools())->map->name()->all();

        $this->assertSame(['read_code', 'modify_code'], $names);
    }

    public function test_artisan_command_prompts_the_agent(): void
    {
        CodeFixer::fake(['変更しました。']);

        $this->artisan('code-agent:run', ['instruction' => 'Video に play() を追加して'])
            ->expectsOutputToContain('変更しました。')
            ->assertSuccessful();

        CodeFixer::assertPrompted('Video に play() を追加して');
    }
}
