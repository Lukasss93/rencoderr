<?php

namespace App\Providers;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAuthenticationEnabled;
use App\Library\Events\LibraryPathScanned;
use App\Models\Library;
use App\Probe\Listeners\QueueProbesAfterScan;
use App\Probe\MediaProber;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FFProbeDriver::class, fn (): FFProbeDriver => FFProbeDriver::create(['ffprobe.binaries' => ['ffprobe'], 'timeout' => MediaProber::TIMEOUT_SECONDS]));
        $this->app->bind(FFMpegDriver::class, fn (): FFMpegDriver => FFMpegDriver::create(configuration: ['ffmpeg.binaries' => ['ffmpeg'], 'timeout' => 5]));
    }

    public function boot(): void
    {
        Relation::morphMap(['library' => Library::class]);

        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'scheduler');
        }

        Event::listen(LibraryPathScanned::class, QueueProbesAfterScan::class);

        Livewire::addPersistentMiddleware([
            Authenticate::class,
            EnsureAuthenticationEnabled::class,
            RedirectIfAuthenticated::class,
        ]);
    }
}
