<?php

namespace App\Workers;

use App\Workers\Enums\WorkerPool;
use App\Workers\Enums\WorkerState;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Events\SupervisorLooped;
use Throwable;

class HorizonWorkers
{
    public const string SUPERVISOR = 'supervisor-1';

    public function __construct(private WorkerLimit $limit, private SupervisorRepository $supervisors) {}

    public static function manages(string $name, WorkerPool $pool = WorkerPool::STANDARD): bool
    {
        return str_ends_with($name, ':'.$pool->supervisor());
    }

    public static function poolFor(string $name): ?WorkerPool
    {
        foreach (WorkerPool::cases() as $pool) {
            if (self::manages($name, $pool)) {
                return $pool;
            }
        }

        return null;
    }

    public function looped(SupervisorLooped $event): void
    {
        if (($pool = self::poolFor($event->supervisor->name)) !== null) {
            $event->supervisor->options->maxProcesses = $this->limit->get($pool);
        }
    }

    public function status(WorkerPool $pool = WorkerPool::STANDARD): WorkerStatus
    {
        try {
            $supervisors = $this->supervisors->all();

            foreach ($supervisors as $supervisor) {
                if (self::manages($supervisor->name, $pool)) {
                    return new WorkerStatus(
                        $supervisor->status === 'paused' ? WorkerState::PAUSED : WorkerState::RUNNING,
                        $supervisor->options['maxProcesses'],
                        array_sum($supervisor->processes),
                    );
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            return new WorkerStatus(WorkerState::UNAVAILABLE);
        }

        return new WorkerStatus(WorkerState::OFFLINE);
    }
}
