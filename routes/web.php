<?php

use App\Http\Controllers\CommunityMapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionActivityController;
use App\Http\Controllers\PublicSurveyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\UserController;
use App\Models\Role;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
    ]);
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

// Community map (authenticated users incl. LGU)
Route::get('map', [CommunityMapController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('map.index');

// Survey management (CAES Officer)
Route::middleware(['auth', 'verified', 'role:'.Role::OFFICER])->group(function () {
    Route::get('surveys/archived', [SurveyController::class, 'archived'])->name('surveys.archived');
    Route::get('surveys/{survey}/aggregation/{metric}', [SurveyController::class, 'aggregationDetail'])->name('surveys.aggregation');
    Route::resource('surveys', SurveyController::class);
    Route::post('surveys/{survey}/publish', [SurveyController::class, 'publish'])->name('surveys.publish');
    Route::post('surveys/{survey}/deactivate', [SurveyController::class, 'deactivate'])->name('surveys.deactivate');
    Route::post('surveys/{survey}/archive', [SurveyController::class, 'archive'])->name('surveys.archive');
    Route::post('surveys/{survey}/restore', [SurveyController::class, 'restore'])->name('surveys.restore');

    // User account management (Officer only)
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

// Extension activities — viewable by Officer (read-only) and Coordinator; manageable only by Coordinator (owner program)
// IMPORTANT: `extensions/create` must be defined BEFORE `extensions/{extensionActivity}` otherwise "create" is captured as ID → 404
Route::middleware(['auth', 'verified', 'role:'.Role::COORDINATOR])->group(function () {
    Route::get('extensions/create', [ExtensionActivityController::class, 'create'])->name('extensions.create');
    Route::post('extensions', [ExtensionActivityController::class, 'store'])->name('extensions.store');
});
Route::middleware(['auth', 'verified', 'role:'.Role::COORDINATOR.','.Role::OFFICER])->group(function () {
    Route::get('extensions/export/{format}', [ExtensionActivityController::class, 'exportBulk'])->name('extensions.export.bulk');
    Route::get('extensions', [ExtensionActivityController::class, 'index'])->name('extensions.index');
    Route::get('extensions/pending', [ExtensionActivityController::class, 'pending'])->name('extensions.pending');
    Route::get('extensions/completed', [ExtensionActivityController::class, 'completed'])->name('extensions.completed');
    Route::get('extensions/{extensionActivity}/documents/{document}/download', [ExtensionActivityController::class, 'downloadDocument'])->name('extensions.documents.download');
    Route::get('extensions/{extensionActivity}/export/{format}', [ExtensionActivityController::class, 'exportSingle'])->name('extensions.export.single');
    Route::get('extensions/{extensionActivity}', [ExtensionActivityController::class, 'show'])->name('extensions.show');
});
Route::middleware(['auth', 'verified', 'role:'.Role::COORDINATOR])->group(function () {
    Route::get('extensions/{extensionActivity}/edit', [ExtensionActivityController::class, 'edit'])->name('extensions.edit');
    Route::put('extensions/{extensionActivity}', [ExtensionActivityController::class, 'update'])->name('extensions.update');
    Route::patch('extensions/{extensionActivity}', [ExtensionActivityController::class, 'update']);
    Route::post('extensions/{extensionActivity}', [ExtensionActivityController::class, 'update']);
    Route::delete('extensions/{extensionActivity}', [ExtensionActivityController::class, 'destroy'])->name('extensions.destroy');
    Route::post('extensions/{extensionActivity}/collaborators', [ExtensionActivityController::class, 'updateCollaborators'])->name('extensions.collaborators.update');
    Route::delete('extensions/{extensionActivity}/documents/{document}', [
        ExtensionActivityController::class, 'destroyDocument',
    ])->name('extensions.documents.destroy');
    // Gantt daily tasks
    Route::post('extensions/{extensionActivity}/daily-tasks', [ExtensionActivityController::class, 'storeDailyTask'])->name('extensions.daily-tasks.store');
    Route::put('extensions/{extensionActivity}/daily-tasks/{task}', [ExtensionActivityController::class, 'updateDailyTask'])->name('extensions.daily-tasks.update');
    Route::delete('extensions/{extensionActivity}/daily-tasks/{task}', [ExtensionActivityController::class, 'destroyDailyTask'])->name('extensions.daily-tasks.destroy');
});

// Reports page removed — export relocated to Extension Activities (full details). Legacy report exports kept for backward compat / tests (hidden from sidebar).
Route::middleware(['auth', 'verified', 'role:'.Role::COORDINATOR.','.Role::OFFICER])->group(function () {
    Route::get('reports/activities/{format}', [ReportController::class, 'activities'])->name('reports.activities');
    Route::get('reports/surveys/{survey}/{format}', [ReportController::class, 'survey'])->name('reports.surveys');
});

// Public survey links (guests, no auth)
Route::get('s/{token}', [PublicSurveyController::class, 'show'])->name('public-surveys.show');
Route::post('s/{token}', [PublicSurveyController::class, 'submit'])->name('public-surveys.submit');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
