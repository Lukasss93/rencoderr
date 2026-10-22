<?php

namespace App\Providers;

use App\Encoding\MediaProber;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAuthenticationEnabled;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FFProbeDriver::class, fn (): FFProbeDriver => FFProbeDriver::create(['ffprobe.binaries' => ['ffprobe'], 'timeout' => MediaProber::TIMEOUT_SECONDS]));
        $this->app->bind(FFMpegDriver::class, fn (): FFMpegDriver => FFMpegDriver::create(configuration: ['ffmpeg.binaries' => ['ffmpeg'], 'timeout' => 5]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'scheduler');
        }

        Livewire::addPersistentMiddleware([
            Authenticate::class,
            EnsureAuthenticationEnabled::class,
            RedirectIfAuthenticated::class,
        ]);
    }
}
