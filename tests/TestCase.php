<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 開発用のデータベースでテストを動かさないよう、アプリを作った直後（RefreshDatabase がテーブルを作り直す前）に確かめる。
     * 設定がキャッシュされていると phpunit.xml の DB_DATABASE=testing が効かず、開発用のデータベースが消える。
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()) {
            throw new RuntimeException('設定がキャッシュされているため、テストを中止しました。`php artisan config:clear` を実行してから、もう一度テストしてください。');
        }

        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if (! $app->environment('testing') || $database !== 'testing') {
            throw new RuntimeException("テスト用でない環境（APP_ENV={$app->environment()}、データベース {$database}）のため、テストを中止しました。");
        }

        return $app;
    }
}
