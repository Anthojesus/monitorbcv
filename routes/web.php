<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('pages::auth.login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('monitor/sites', 'pages::monitor.sites')->name('monitor.sites');
    Route::livewire('monitor/sites/{target}', 'pages::monitor.site')->name('monitor.sites.show');
    Route::livewire('monitor/apis', 'pages::monitor.apis')->name('monitor.apis');
    Route::livewire('documentacion', 'pages::documentation')->name('documentation');
});

require __DIR__.'/settings.php';
