<?php

namespace App\Providers;

use App\Services\Analysis\DummyVideoAnalyzer;
use App\Services\Analysis\GeminiVideoAnalyzer;
use App\Services\Analysis\VideoAnalyzer;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(YouTubeClient::class, fn () => new YouTubeClient(config('services.youtube.key')));

        $this->app->bind(VideoAnalyzer::class, fn ($app) => match (config('services.video_analyzer.driver')) {
            // .env で空欄（GEMINI_ANALYSIS_MODEL=）のときは SDK の既定モデルを使う。
            'gemini' => new GeminiVideoAnalyzer(
                config('services.video_analyzer.gemini_model') ?: null,
                config('services.video_analyzer.gemini_fallback_models'),
            ),
            default => $app->make(DummyVideoAnalyzer::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
