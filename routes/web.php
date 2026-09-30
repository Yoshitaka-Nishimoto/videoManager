<?php

use App\Http\Controllers\Admin\CodeChangeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('dashboard', 'dashboard')->middleware('auth')->name('dashboard');

// TODO: 認証を導入したら auth ミドルウェアに置き換える。それまではローカル環境のみ公開。
if (app()->environment('local', 'testing')) {
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('code-changes', [CodeChangeController::class, 'index'])->name('code-changes.index');
        Route::get('code-changes/{codeChange}', [CodeChangeController::class, 'show'])->name('code-changes.show');
    });
}
