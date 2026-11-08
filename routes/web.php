<?php

use App\Http\Controllers\CandidateApplicationController;
use App\Http\Controllers\CandidateInterviewController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobPositionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::patch('candidates/{candidate}', [CandidateApplicationController::class, 'update'])->name('candidates.update');
    Route::post('candidates/{candidate}/archive', [CandidateApplicationController::class, 'archive'])->name('candidates.archive');
    Route::post('candidates/{candidate}/restore', [CandidateApplicationController::class, 'restore'])->name('candidates.restore');
    Route::delete('candidates/{candidate}', [CandidateApplicationController::class, 'destroy'])->name('candidates.destroy');
    Route::get('positions/{position}/interviews', [InterviewController::class, 'index'])->name('interviews.index');
    Route::post('positions/{position}/interviews', [InterviewController::class, 'store'])->name('interviews.store');
    Route::get('interviews/{interview}', [InterviewController::class, 'show'])->name('interviews.show');
    Route::get('interviews/{interview}/regenerate-link', [InterviewController::class, 'linkRedirect'])->name('interviews.link-confirmation');
    Route::post('interviews/{interview}/regenerate-link', [InterviewController::class, 'regenerateLink'])->middleware('throttle:10,1')->name('interviews.regenerate-link');
    Route::post('interviews/{interview}/evaluate', [InterviewController::class, 'evaluate'])->middleware('throttle:10,1')->name('interviews.evaluate');
    Route::resource('positions', JobPositionController::class)->except('destroy');
    Route::post('positions/{position}/archive', [JobPositionController::class, 'archive'])->name('positions.archive');

    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

Route::get('candidate/interviews/{token}', [CandidateInterviewController::class, 'show'])->name('candidate.interviews.show');
Route::post('candidate/interviews/{token}', [CandidateInterviewController::class, 'update'])->middleware('throttle:20,1')->name('candidate.interviews.update');
