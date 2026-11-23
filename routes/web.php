<?php

use App\Http\Controllers\MonitoringController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::middleware(['auth', 'verified'])->controller(MonitoringController::class)->group(function () {
    Route::get('dashboard', 'index')->name('dashboard');
    Route::get('monitoring/{section}', 'index')->whereIn('section', ['ringkasan', 'wbs', 'builder', 'input', 'baru'])->name('workspace');
    Route::post('projects', 'store')->name('projects.store');
    Route::put('projects/{project}/structure', 'structure')->name('projects.structure');
    Route::post('projects/{project}/periods', 'period')->name('projects.period');
    Route::patch('projects/{project}/periods/{period}', 'update')->name('projects.update');
});
require __DIR__.'/settings.php';
