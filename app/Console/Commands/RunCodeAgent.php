<?php

namespace App\Console\Commands;

use App\Ai\Agents\CodeFixer;
use App\Models\CodeChange;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('code-agent:run {instruction : エージェントへの指示（例: "app/Models/User.php に isAdmin() を追加して"）}')]
#[Description('CodeFixer エージェントにコード変更を依頼する（変更は tree-sitter で検証してから適用）')]
class RunCodeAgent extends Command
{
    public function handle(): int
    {
        $startedAt = now();

        $response = CodeFixer::make()->prompt($this->argument('instruction'));

        $this->line((string) $response);
        $this->newLine();

        $changes = CodeChange::where('created_at', '>=', $startedAt)->oldest('id')->get();

        $this->table(
            ['#', 'status', 'path', 'reason'],
            $changes->map(fn (CodeChange $c) => [$c->id, $c->status, $c->path, str($c->reason)->limit(60)]),
        );

        return self::SUCCESS;
    }
}
