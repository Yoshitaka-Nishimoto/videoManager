<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ModifyCode;
use App\Ai\Tools\ReadCode;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Anthropic)]
#[MaxSteps(10)]
#[Timeout(120)]
class CodeFixer implements Agent, HasTools
{
    use Promptable;

    /**
     * あなたは、このアプリケーションのPHPとBladeコードを編集する慎重なLaravelエンジニアです。
     */
    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        1. 変更する前に、read_codeを使って対象ファイルを読みます。
        2. modify_codeを使って、変更を加えるための最小限の正確な`search`スニペットで修正します。
        3. modify_codeは結果をtree-sitterで検証します。もしREJECTEDが返ってきたら、エラーを読み、コードを修正して再試行してください。一度の拒否で諦めてはいけません。
        4. 最後に、何を変更したか、なぜ変更したかを簡単に日本語でまとめます。

        app/とresources/views/以下のファイルのみ触ることができます。
        TXT;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            app(ReadCode::class),
            app(ModifyCode::class),
        ];
    }
}
