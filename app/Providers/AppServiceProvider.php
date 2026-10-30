<?php

namespace App\Providers;

use App\Services\Ai\TextAi;
use App\Services\Analysis\DummyVideoAnalyzer;
use App\Services\Analysis\GeminiPersonalFileAnalyzer;
use App\Services\Analysis\GeminiVideoAnalyzer;
use App\Services\Analysis\GeminiWebPageAnalyzer;
use App\Services\Analysis\SourceTypeAnalyzer;
use App\Services\Analysis\VideoAnalyzer;
use App\Services\Files\PersonalFileReader;
use App\Services\Runway\RunwayClient;
use App\Services\Script\DummyScriptWriter;
use App\Services\Script\GeminiScriptWriter;
use App\Services\Script\ScriptWriter;
use App\Services\Web\WebPageClient;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Enums\Lab;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(YouTubeClient::class, fn () => new YouTubeClient(config('services.youtube.key')));

        $this->app->singleton(RunwayClient::class, fn () => new RunwayClient(
            config('services.runway.key'),
            config('services.runway.base_url'),
            config('services.runway.version'),
        ));

        $this->app->bind(VideoAnalyzer::class, fn ($app) => match (config('services.video_analyzer.driver')) {
            // .env で空欄（GEMINI_ANALYSIS_MODEL=）のときは SDK の既定モデルを使う。
            'gemini' => new SourceTypeAnalyzer(
                video: new GeminiVideoAnalyzer(
                    config('services.video_analyzer.gemini_model') ?: null,
                    config('services.video_analyzer.gemini_fallback_models'),
                ),
                // Web ページ・個人のファイルは文章なので、text_provider の AI で分析する。
                webPage: new GeminiWebPageAnalyzer($app->make(WebPageClient::class), ...$this->textAiArguments()),
                file: new GeminiPersonalFileAnalyzer($app->make(PersonalFileReader::class), ...$this->textAiArguments()),
            ),
            default => $app->make(DummyVideoAnalyzer::class),
        });

        $this->app->bind(ScriptWriter::class, fn ($app) => match (config('services.script_writer.driver')) {
            'gemini' => new GeminiScriptWriter(TextAi::fromConfig()->models(), TextAi::fromConfig()->provider),
            default => $app->make(DummyScriptWriter::class),
        });
    }

    /**
     * 文章の分析のコンストラクタに渡す、AI とモデル（TextAi）。
     *
     * @return array{model: ?string, fallbackModels: list<string>, provider: Lab}
     */
    private function textAiArguments(): array
    {
        $text = TextAi::fromConfig();

        return ['model' => $text->model, 'fallbackModels' => $text->fallbackModels, 'provider' => $text->provider];
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // php artisan dev で、Remotion の書き出し専用のワーカーも起動する（config/queue.php の remotion）。
        DevCommands::artisan('queue:listen remotion --tries=1 --timeout=0', 'remotion');
    }
}
