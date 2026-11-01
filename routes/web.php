<?php

use App\Http\Controllers\JobPositionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('positions', JobPositionController::class)->except('destroy');
    Route::post('positions/{position}/archive', [JobPositionController::class, 'archive'])->name('positions.archive');

    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
