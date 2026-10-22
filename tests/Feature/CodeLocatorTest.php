<?php

namespace Tests\Feature;

use App\Services\Knowledge\CodeLocator;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\BuildsFakeRepository;
use Tests\TestCase;

class CodeLocatorTest extends TestCase
{
    use BuildsFakeRepository;

    private CodeLocator $locator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildFakeRepository();
        $this->locator = app(CodeLocator::class);
    }

    public function test_paths_are_suggested_only_from_the_allowed_folders(): void
    {
        $this->assertSame(['app/Services/Runway/InputImageChecker.php'], $this->locator->suggestPaths('checker'));
        $this->assertSame(['remotion/src/scenes/TitleScene.tsx'], $this->locator->suggestPaths('scenes\\title'));
        $this->assertSame([], $this->locator->suggestPaths('  '));
    }

    public function test_only_existing_files_inside_the_allowed_folders_are_accepted(): void
    {
        $this->assertSame('app/Services/Runway/InputImageChecker.php', $this->locator->normalize('./app\\Services\\Runway\\InputImageChecker.php'));
        $this->assertNull($this->locator->normalize('app/Services/Runway/Missing.php'));
        $this->assertNull($this->locator->normalize('vendor/laravel/Checker.php'));
        $this->assertNull($this->locator->normalize('app/../vendor/laravel/Checker.php'));
        $this->assertNull($this->locator->normalize('app/Services/Runway'));
    }

    public function test_symbols_are_read_from_the_file_with_their_lines(): void
    {
        $this->assertSame([
            ['symbol' => 'InputImageChecker', 'line' => 5],
            ['symbol' => 'InputImageChecker::check', 'line' => 7],
            ['symbol' => 'InputImageChecker::resolution', 'line' => 12],
        ], $this->locator->symbolsIn('app/Services/Runway/InputImageChecker.php'));

        $this->assertSame([
            ['symbol' => 'TitleScene', 'line' => 3],
            ['symbol' => 'useTitle', 'line' => 7],
        ], $this->locator->symbolsIn('remotion/src/scenes/TitleScene.tsx'));
    }

    public function test_locating_fills_the_line_commit_and_graph_node(): void
    {
        $location = $this->locator->locate('app/Services/Runway/InputImageChecker.php', 'InputImageChecker::check', endLine: 10);

        $this->assertSame(7, $location->startLine);
        $this->assertSame(10, $location->endLine);
        $this->assertSame(self::FAKE_COMMIT, $location->commitSha);
        $this->assertSame('app_services_runway_inputimagechecker_inputimagechecker_check', $location->graphifyNodeId);
    }

    public function test_a_whole_file_matches_the_file_node_and_a_symbol_missing_from_the_graph_has_no_node(): void
    {
        $this->assertSame('app_services_runway_inputimagechecker', $this->locator->locate('app/Services/Runway/InputImageChecker.php')->graphifyNodeId);

        $location = $this->locator->locate('app/Services/Runway/InputImageChecker.php', 'InputImageChecker::resolution');
        $this->assertSame(12, $location->startLine);
        $this->assertNull($location->graphifyNodeId);
    }

    public function test_an_unknown_file_or_reversed_lines_are_rejected(): void
    {
        try {
            $this->locator->locate('app/Missing.php');
            $this->fail('A missing file was accepted.');
        } catch (KnowledgeRuleViolation $e) {
            $this->assertStringContainsString('app/Missing.php', $e->getMessage());
        }

        $this->expectException(KnowledgeRuleViolation::class);

        $this->locator->locate('app/Services/Runway/InputImageChecker.php', startLine: 10, endLine: 5);
    }

    public function test_the_commit_is_read_from_packed_refs_and_the_github_url_from_the_remote(): void
    {
        File::delete("{$this->fakeRepository}/.git/refs/heads/feature/x");
        File::put("{$this->fakeRepository}/.git/packed-refs", "# pack-refs with: peeled\n".self::FAKE_COMMIT." refs/heads/feature/x\n");

        $this->assertSame(self::FAKE_COMMIT, $this->locator->currentCommit());
        $this->assertSame('https://github.com/owner/repo', $this->locator->githubRepositoryUrl());
    }

    public function test_without_git_or_graph_there_is_no_commit_url_or_node(): void
    {
        File::deleteDirectory("{$this->fakeRepository}/.git");
        File::deleteDirectory("{$this->fakeRepository}/graphify-out");
        $locator = new CodeLocator($this->fakeRepository);

        $location = $locator->locate('app/Services/Runway/InputImageChecker.php', 'InputImageChecker::check');

        $this->assertNull($location->commitSha);
        $this->assertNull($location->graphifyNodeId);
        $this->assertNull($locator->githubRepositoryUrl());
    }
}
