@props(['check'])
@use('App\Tasks\Enums\TaskStatus')
@use('App\QualityControl\Enums\QualityVerdict')

<x-badge :text="'Quality control: '.($check->verdict ? ucfirst($check->verdict->value) : ucfirst($check->status->value))"
    :color="match (true) {
        $check->verdict === QualityVerdict::PASSED => 'green',
        $check->verdict === QualityVerdict::REJECTED, $check->status === TaskStatus::FAILED => 'red',
        in_array($check->status, [TaskStatus::RUNNING, TaskStatus::QUEUED], true) => 'blue',
        default => 'gray',
    }" />
