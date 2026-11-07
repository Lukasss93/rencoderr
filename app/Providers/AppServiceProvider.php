<?php

namespace App\Providers;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAuthenticationEnabled;
use App\Library\Events\LibraryPathScanned;
use App\Library\Events\MediaFileReplaced;
use App\Library\Listeners\RefreshAfterWriteback;
use App\Models\Library;
use App\Models\MediaTranscode;
use App\Probe\Listeners\QueueProbeAfterReplacement;
use App\Probe\Listeners\QueueProbesAfterScan;
use App\Probe\MediaProber;
use App\QualityControl\Events\QualityCheckPassed;
use App\QualityControl\Listeners\QueueQualityCheck;
use App\Transcoding\Events\TranscodeCompleted;
use App\Writeback\Events\WritebackInstalled;
use App\Writeback\Listeners\QueueWriteback;
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
        Relation::morphMap(['library' => Library::class, 'media_transcode' => MediaTranscode::class]);

        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'scheduler');
        }

        Event::listen(LibraryPathScanned::class, QueueProbesAfterScan::class);
        Event::listen(TranscodeCompleted::class, QueueQualityCheck::class);
        Event::listen(QualityCheckPassed::class, QueueWriteback::class);
        Event::listen(WritebackInstalled::class, RefreshAfterWriteback::class);
        Event::listen(MediaFileReplaced::class, QueueProbeAfterReplacement::class);

        Livewire::addPersistentMiddleware([
            Authenticate::class,
            EnsureAuthenticationEnabled::class,
            RedirectIfAuthenticated::class,
        ]);
    }
}
