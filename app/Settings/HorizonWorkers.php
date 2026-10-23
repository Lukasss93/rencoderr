<?php

namespace App\Settings;

use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Events\SupervisorLooped;
use Throwable;

class HorizonWorkers
{
    public function __construct(private ApplicationSettings $settings, private SupervisorRepository $supervisors) {}

    public function looped(SupervisorLooped $event): void
    {
        if (self::manages($event->supervisor->name)) {
            $event->supervisor->options->maxProcesses = $this->settings->queueMaxWorkers();
        }
    }

    /** @return array{state: 'running'|'paused'|'offline'|'unavailable', limit: int|null, workers: int|null} */
    public function status(): array
    {
        try {
            /** @var list<object{name: string, status: string, processes: array<string, int>, options: array{maxProcesses: int}}> $supervisors */
            $supervisors = $this->supervisors->all();

            foreach ($supervisors as $supervisor) {
                if (self::manages($supervisor->name)) {
                    return [
                        'state' => $supervisor->status === 'paused' ? 'paused' : 'running',
                        'limit' => $supervisor->options['maxProcesses'],
                        'workers' => array_sum($supervisor->processes),
                    ];
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            return ['state' => 'unavailable', 'limit' => null, 'workers' => null];
        }

        return ['state' => 'offline', 'limit' => null, 'workers' => null];
    }

    public static function manages(string $name): bool
    {
        return str_ends_with($name, ':supervisor-1');
    }
}
