<?php

use App\Http\Controllers\MonitoringController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request): RedirectResponse => redirect()->route(
    $request->user() ? 'dashboard' : 'login',
))->name('home');
Route::middleware(['auth', 'verified'])->controller(MonitoringController::class)->group(function () {
    Route::get('dashboard', 'index')->name('dashboard');
    Route::get('monitoring/{section}', 'index')->whereIn('section', ['ringkasan', 'estimasi', 'wbs', 'builder', 'input', 'baru'])->name('workspace');
    Route::get('monitoring/templates/form-builder', 'downloadFormBuilderTemplate')->name('projects.formBuilderTemplate');
    Route::get('monitoring/templates/form-builder/example', 'downloadFormBuilderExample')->name('projects.formBuilderExample');
    Route::post('projects', 'store')->name('projects.store');
    Route::get('projects/{project}/edit', 'edit')->whereNumber('project')->name('projects.edit');
    Route::patch('projects/{project}', 'updateIdentity')->whereNumber('project')->name('projects.updateIdentity');
    Route::put('projects/{project}/estimate', 'updateEstimate')->whereNumber('project')->name('projects.estimate.update');
    Route::put('projects/{project}/structure', 'structure')->name('projects.structure');
    Route::post('projects/{project}/form-builder/import/preview', 'previewFormBuilderImport')->whereNumber('project')->name('projects.formBuilderImport.preview');
    Route::post('projects/{project}/form-builder/import', 'applyFormBuilderImport')->whereNumber('project')->name('projects.formBuilderImport.apply');
    Route::post('projects/{project}/periods', 'period')->name('projects.period');
    Route::patch('projects/{project}/periods/{period}', 'update')->name('projects.update');
    Route::delete('projects/{project}/periods/{period}', 'destroyPeriod')->whereNumber(['project', 'period'])->name('projects.periods.destroy');
});
require __DIR__.'/settings.php';
