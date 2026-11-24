<?php

use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\MonitoringImportController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request): RedirectResponse => redirect()->route(
    $request->user() ? 'dashboard' : 'login',
))->name('home');
Route::middleware(['auth', 'verified'])->controller(MonitoringController::class)->group(function () {
    Route::get('monitoring-template', [MonitoringImportController::class, 'template'])->name('projects.template');
    Route::post('projects/{project}/import/preview', [MonitoringImportController::class, 'preview'])->whereNumber('project')->name('projects.previewImport');
    Route::post('projects/{project}/import/apply', [MonitoringImportController::class, 'apply'])->whereNumber('project')->name('projects.applyImport');
    Route::get('dashboard', 'index')->name('dashboard');
    Route::get('monitoring/{section}', 'index')->whereIn('section', ['ringkasan', 'wbs', 'builder', 'input', 'estimasi', 'baru'])->name('workspace');
    Route::post('projects', 'store')->name('projects.store');
    Route::get('projects/{project}/edit', 'edit')->whereNumber('project')->name('projects.edit');
    Route::patch('projects/{project}', 'updateIdentity')->whereNumber('project')->name('projects.updateIdentity');
    Route::put('projects/{project}/estimate', 'estimate')->name('projects.estimate');
    Route::put('projects/{project}/structure', 'structure')->name('projects.structure');
    Route::put('projects/{project}/schedule', 'schedule')->name('projects.schedule');
    Route::post('projects/{project}/periods/{period}/correction', 'correct')->name('projects.correct');
    Route::post('projects/{project}/periods', 'period')->name('projects.period');
    Route::patch('projects/{project}/periods/{period}', 'update')->name('projects.update');
});
require __DIR__.'/settings.php';
