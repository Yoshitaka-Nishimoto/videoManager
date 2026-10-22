<?php

namespace Tests\Feature\Concerns;

use App\Services\Knowledge\CodeLocator;
use Illuminate\Support\Facades\File;

/**
 * CodeLocator が読むリポジトリ（ファイル・.git・graph.json）を一時フォルダに作る。
 */
trait BuildsFakeRepository
{
    protected const FAKE_COMMIT = '0123456789abcdef0123456789abcdef01234567';

    protected ?string $fakeRepository = null;

    protected function buildFakeRepository(): string
    {
        $this->fakeRepository = sys_get_temp_dir().'/fake-repo-'.bin2hex(random_bytes(6));

        $files = [
            'app/Services/Runway/InputImageChecker.php' => <<<'PHP'
                <?php

                namespace App\Services\Runway;

                final class InputImageChecker
                {
                    public function check(string $path): bool
                    {
                        return $this->resolution($path) >= 720;
                    }

                    private static function resolution(string $path): int
                    {
                        return 1080;
                    }
                }
                PHP,
            'remotion/src/scenes/TitleScene.tsx' => "import React from 'react';\n\nexport const TitleScene: React.FC = () => {\n  return null;\n};\n\nexport function useTitle() {}\n",
            'vendor/laravel/Checker.php' => "<?php\n",
            'remotion/node_modules/pkg/Checker.js' => '',
            '.git/HEAD' => "ref: refs/heads/feature/x\n",
            '.git/refs/heads/feature/x' => self::FAKE_COMMIT."\n",
            '.git/config' => "[core]\n\tbare = false\n[remote \"origin\"]\n\turl = https://github.com/owner/repo.git\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n",
            'graphify-out/graph.json' => json_encode(['nodes' => [
                ['id' => 'app_services_runway_inputimagechecker', 'label' => 'InputImageChecker.php', 'source_file' => 'app/Services/Runway/InputImageChecker.php', 'source_location' => 'L1'],
                ['id' => 'app_services_runway_inputimagechecker_inputimagechecker_check', 'label' => '.check()', 'source_file' => 'app/Services/Runway/InputImageChecker.php', 'source_location' => 'L7'],
            ]]),
        ];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$this->fakeRepository}/{$path}"));
            File::put("{$this->fakeRepository}/{$path}", $contents);
        }

        app()->instance(CodeLocator::class, new CodeLocator($this->fakeRepository));

        return $this->fakeRepository;
    }

    protected function tearDown(): void
    {
        if ($this->fakeRepository !== null) {
            File::deleteDirectory($this->fakeRepository);
        }

        parent::tearDown();
    }
}
