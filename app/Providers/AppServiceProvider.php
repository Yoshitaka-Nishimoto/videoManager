<?php

namespace App\Providers;

use App\Services\Analysis\DummyVideoAnalyzer;
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

        // TODO: 本物の AI（Gemini など）で分析する実装ができたら差し替える。
        $this->app->bind(VideoAnalyzer::class, DummyVideoAnalyzer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
