<?php

namespace App\Workers;

use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Throwable;

/**
 * Starts the managed supervisor with the saved worker limit instead of the configured default.
 */
class ProvisionHorizonSupervisor extends AddSupervisor
{
    public function __construct(private WorkerLimit $limit, private HorizonCommandQueue $commands) {}

    /** @param array<string, mixed> $options */
    public function process(MasterSupervisor $master, array $options): void
    {
        if (($pool = HorizonWorkers::poolFor($options['name'])) !== null) {
            try {
                $options['maxProcesses'] = $this->limit->get($pool);
            } catch (Throwable $exception) {
                // Queue the command again so the supervisor still starts once the setting can be read.
                $this->commands->push(MasterSupervisor::commandQueueFor($master->name), AddSupervisor::class, $options);

                throw $exception;
            }
        }

        parent::process($master, $options);
    }
}
