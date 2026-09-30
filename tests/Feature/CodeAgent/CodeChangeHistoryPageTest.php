<?php

namespace Tests\Feature\CodeAgent;

use App\Models\CodeChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeChangeHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_lists_changes_and_shows_diff(): void
    {
        $applied = CodeChange::create([
            'path' => 'app/Models/Video.php',
            'language' => 'php',
            'status' => CodeChange::STATUS_APPLIED,
            'reason' => 'duration() を追加',
            'diff' => "--- a/app/Models/Video.php\n+++ b/app/Models/Video.php\n@@ -1,1 +1,2 @@\n+    public function duration(): int\n",
            'symbols_added' => ['Video::duration'],
        ]);

        CodeChange::create([
            'path' => 'resources/views/video.blade.php',
            'language' => 'blade',
            'status' => CodeChange::STATUS_REJECTED,
            'reason' => '壊れた Blade',
            'diff' => '',
            'ast_errors' => [['line' => 3, 'column' => 7, 'kind' => 'syntax error', 'snippet' => '<?php if($x): ?>']],
        ]);

        $this->get(route('admin.code-changes.index'))
            ->assertOk()
            ->assertSee('app/Models/Video.php')
            ->assertSee('resources/views/video.blade.php')
            ->assertSee('rejected');

        $this->get(route('admin.code-changes.show', $applied))
            ->assertOk()
            ->assertSee('Video::duration')
            ->assertSee('public function duration(): int');
    }
}
