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

@if ($transcode?->writeback)
    <x-badge :text="$transcode->writeback->status === TaskStatus::FAILED ? 'Replacement failed' : match ($transcode->writeback->phase) {
        'copying' => 'Copying replacement',
        'awaiting_approval' => $transcode->writeback->approved_at === null ? 'Awaiting replacement approval' : 'Replacement queued',
        'replacing' => 'Replacing original',
        'installed' => 'Replacement installed',
        default => ucfirst(str_replace('_', ' ', $transcode->writeback->phase)),
    }" :color="match ($transcode->writeback->status) {
        TaskStatus::FAILED => 'red',
        TaskStatus::COMPLETED => 'green',
        TaskStatus::PENDING => 'yellow',
        default => 'blue',
    }" />
@endif

@if ($transcode?->status === TaskStatus::COMPLETED)
    @if ($transcode->qualityCheck)
        <x-media.quality-status :check="$transcode->qualityCheck" />
    @else
        <x-badge text="Awaiting quality control" color="gray" />
    @endif
@endif
