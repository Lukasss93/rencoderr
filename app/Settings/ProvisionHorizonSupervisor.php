<?php

namespace App\Settings;

use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Throwable;

class ProvisionHorizonSupervisor extends AddSupervisor
{
    public function __construct(private ApplicationSettings $settings, private HorizonCommandQueue $commands) {}

    /** @param array<string, mixed> $options */
    public function process(MasterSupervisor $master, array $options): void
    {
        if (HorizonWorkers::manages($options['name'])) {
            try {
                $options['maxProcesses'] = $this->settings->queueMaxWorkers();
            } catch (Throwable $exception) {
                $this->commands->push(MasterSupervisor::commandQueueFor($master->name), AddSupervisor::class, $options);

                throw $exception;
            }
        }

        parent::process($master, $options);
    }
}
