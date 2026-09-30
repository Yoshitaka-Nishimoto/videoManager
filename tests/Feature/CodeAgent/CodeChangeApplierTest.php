<?php

namespace Tests\Feature\CodeAgent;

use App\Models\CodeChange;
use App\Services\CodeAgent\CodeChangeApplier;
use App\Services\CodeAgent\CodeChangeException;
use PHPUnit\Framework\Attributes\DataProvider;

class CodeChangeApplierTest extends CodeAgentTestCase
{
    private const VIDEO_MODEL = <<<'PHP'
    <?php

    namespace App\Models;

    class Video
    {
        public function title(): string
        {
            return 'untitled';
        }
    }

    PHP;

    public function test_valid_php_change_is_applied_and_logged(): void
    {
        $this->sandboxFile('app/Models/Video.php', self::VIDEO_MODEL);

        $change = app(CodeChangeApplier::class)->apply(
            path: 'app/Models/Video.php',
            search: "            return 'untitled';\n        }\n",
            replace: "            return 'untitled';\n        }\n\n        public function duration(): int\n        {\n            return 0;\n        }\n",
            reason: 'duration() を追加',
        );

        $this->assertTrue($change->isApplied());
        $this->assertStringContainsString('public function duration(): int', $this->sandboxFile('app/Models/Video.php'));
        $this->assertStringContainsString('+        public function duration(): int', $change->diff);
        $this->assertSame(['Video::duration'], $change->symbols_added);
        $this->assertDatabaseHas('code_changes', ['path' => 'app/Models/Video.php', 'status' => 'applied']);
    }

    public function test_broken_php_is_rejected_and_file_is_untouched(): void
    {
        $this->sandboxFile('app/Models/Video.php', self::VIDEO_MODEL);

        $change = app(CodeChangeApplier::class)->apply(
            path: 'app/Models/Video.php',
            search: "return 'untitled';",
            replace: "return 'untitled'", // セミコロン抜け
            reason: '壊れた変更',
        );

        $this->assertSame(CodeChange::STATUS_REJECTED, $change->status);
        $this->assertNotEmpty($change->ast_errors);
        $this->assertSame(self::VIDEO_MODEL, $this->sandboxFile('app/Models/Video.php'));
        $this->assertDatabaseHas('code_changes', ['status' => 'rejected']);
    }

    public function test_valid_blade_change_is_applied(): void
    {
        $this->sandboxFile('resources/views/video.blade.php', "<h1>{{ \$video->title() }}</h1>\n");

        $change = app(CodeChangeApplier::class)->apply(
            path: 'resources/views/video.blade.php',
            search: "<h1>{{ \$video->title() }}</h1>\n",
            replace: "<h1>{{ \$video->title() }}</h1>\n@if (\$video->duration() > 0)\n    <p>{{ \$video->duration() }} 秒</p>\n@endif\n",
            reason: '再生時間を表示',
        );

        $this->assertTrue($change->isApplied());
        $this->assertSame('blade', $change->language);
    }

    public function test_blade_with_unclosed_if_is_rejected(): void
    {
        $original = "<h1>{{ \$video->title() }}</h1>\n";
        $this->sandboxFile('resources/views/video.blade.php', $original);

        $change = app(CodeChangeApplier::class)->apply(
            path: 'resources/views/video.blade.php',
            search: $original,
            replace: $original."@if (\$video->duration() > 0)\n    <p>{{ \$video->duration() }}</p>\n", // @endif 抜け
            reason: '壊れた Blade',
        );

        $this->assertFalse($change->isApplied());
        $this->assertSame($original, $this->sandboxFile('resources/views/video.blade.php'));
    }

    public function test_new_file_can_be_created(): void
    {
        $change = app(CodeChangeApplier::class)->apply(
            path: 'app/Models/Channel.php',
            search: '',
            replace: "<?php\n\nnamespace App\\Models;\n\nclass Channel {}\n",
            reason: '新規モデル',
        );

        $this->assertTrue($change->isApplied());
        $this->assertFileExists($this->sandbox.'/app/Models/Channel.php');
    }

    #[DataProvider('forbiddenPaths')]
    public function test_paths_outside_allowed_directories_are_refused(string $path): void
    {
        $this->expectException(CodeChangeException::class);

        app(CodeChangeApplier::class)->apply($path, '', "<?php\n", 'escape');
    }

    public static function forbiddenPaths(): array
    {
        return [
            'traversal' => ['app/../.env.php'],
            'absolute' => ['/etc/passwd.php'],
            'config dir' => ['config/app.php'],
            'routes dir' => ['routes/web.php'],
            'non php' => ['app/notes.txt'],
        ];
    }

    public function test_ambiguous_search_is_refused(): void
    {
        $this->sandboxFile('app/Models/Dup.php', "<?php\n\$a = 1;\n\$a = 1;\n");

        $this->expectException(CodeChangeException::class);
        $this->expectExceptionMessage('2 箇所');

        app(CodeChangeApplier::class)->apply('app/Models/Dup.php', '$a = 1;', '$a = 2;', 'dup');
    }
}
