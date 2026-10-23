<?php

use App\Http\Controllers\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Queue;
use App\Livewire\Settings\Libraries;
use App\Livewire\Settings\Status;
use App\Livewire\Settings\Users;
use App\Livewire\Settings\Workers;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', Login::class)
    ->middleware(['auth.enabled', 'guest'])
    ->name('login');

Route::post('/logout', LogoutController::class)
    ->middleware(['auth.enabled', 'auth'])
    ->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::livewire('/', Dashboard::class)->name('home');
    Route::livewire('/queue', Queue::class)->name('queue.index');
    Route::redirect('/settings', '/settings/status')->name('settings.index');
    Route::livewire('/settings/status', Status::class)->name('settings.status');
    Route::livewire('/settings/libraries', Libraries::class)->name('settings.libraries');
    Route::livewire('/settings/users', Users::class)->middleware('auth.enabled')->name('settings.users');
    Route::livewire('/settings/workers', Workers::class)->name('settings.workers');
    Route::redirect('/settings/system-health', '/settings/status')->name('settings.system-health');
    Route::redirect('/libraries', '/settings/libraries')->name('libraries.index');
});
