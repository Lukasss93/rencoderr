<?php

namespace App\Providers;

use App\Workers\HorizonWorkers;
use App\Workers\ProvisionHorizonSupervisor;
use App\Workers\WorkerLimit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;

class HorizonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(AddSupervisor::class, fn (AddSupervisor $command, Application $app): ProvisionHorizonSupervisor => new ProvisionHorizonSupervisor(
            $command, $app->make(WorkerLimit::class), $app->make(HorizonCommandQueue::class),
        ));
    }

    public function boot(): void
    {
        Horizon::auth(fn (Request $request): bool => ! Config::boolean('auth.enabled') || $request->user() !== null);

        Event::listen(SupervisorLooped::class, [HorizonWorkers::class, 'looped']);
    }
}
