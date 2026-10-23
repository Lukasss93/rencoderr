<?php

namespace App\Providers;

use App\Settings\HorizonWorkers;
use App\Settings\ProvisionHorizonSupervisor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;

class HorizonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AddSupervisor::class, ProvisionHorizonSupervisor::class);
    }

    public function boot(): void
    {
        Horizon::auth(fn (Request $request): bool => ! Config::boolean('auth.enabled') || $request->user() !== null);

        Event::listen(SupervisorLooped::class, [HorizonWorkers::class, 'looped']);
    }
}
