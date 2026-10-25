@props(['transcode'])
@use('App\Tasks\Enums\TaskStatus')

<x-badge :text="$transcode === null ? 'Not transcoded' : ($transcode->status === TaskStatus::COMPLETED ? 'Encoded' : ucfirst($transcode->status->value))"
    :color="match ($transcode?->status) {
        TaskStatus::COMPLETED => 'green',
        TaskStatus::FAILED => 'red',
        TaskStatus::RUNNING, TaskStatus::QUEUED => 'blue',
        default => 'gray',
    }" />

@if ($transcode?->status === TaskStatus::RUNNING && $transcode->phase)
    <x-badge :text="ucfirst(str_replace('_', ' ', $transcode->phase))" color="blue" />
@endif

@if ($transcode?->status === TaskStatus::COMPLETED)
    @if ($transcode->qualityCheck)
        <x-media.quality-status :check="$transcode->qualityCheck" />
    @else
        <x-badge text="Awaiting quality control" color="gray" />
    @endif
@endif
