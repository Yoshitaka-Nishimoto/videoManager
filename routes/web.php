<?php

use App\Http\Controllers\Admin\CodeChangeController;
use App\Http\Controllers\ProductionRenderFileController;
use App\Http\Controllers\RemotionStudioController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('dashboard', 'dashboard')->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::livewire('videos', 'pages::videos.index')->name('videos.index');
    Route::livewire('videos/{video}', 'pages::videos.show')->name('videos.show');
    Route::livewire('knowledge', 'pages::knowledge.index')->name('knowledge.index');
    Route::livewire('knowledge/{node}', 'pages::knowledge.show')->name('knowledge.show');
    Route::livewire('ai-usage', 'pages::ai-usage.index')->name('ai-usage.index');
    Route::livewire('productions', 'pages::productions.index')->name('productions.index');
    Route::livewire('productions/{production}', 'pages::productions.show')->name('productions.show');
    Route::get('production-renders/{render}/file', ProductionRenderFileController::class)->name('production-renders.file');
    Route::get('remotion-studio/{path?}', RemotionStudioController::class)->where('path', '.*')->name('remotion-studio');
});

// TODO: 認証を導入したら auth ミドルウェアに置き換える。それまではローカル環境のみ公開。
if (app()->environment('local', 'testing')) {
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('code-changes', [CodeChangeController::class, 'index'])->name('code-changes.index');
        Route::get('code-changes/{codeChange}', [CodeChangeController::class, 'show'])->name('code-changes.show');
    });
}
