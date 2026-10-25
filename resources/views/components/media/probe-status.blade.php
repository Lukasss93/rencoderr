@props(['file'])
@use('App\Tasks\Enums\TaskStatus')

<x-badge :text="match (true) {
    $file->probe === null => 'Not probed',
    $file->probe->status === TaskStatus::COMPLETED && ! $file->probe->matches($file) => 'Stale metadata',
    $file->probe->status === TaskStatus::COMPLETED => 'Current metadata',
    default => ucfirst($file->probe->status->value),
}" :color="match (true) {
    $file->probe === null => 'gray',
    $file->probe->status === TaskStatus::FAILED => 'red',
    $file->probe->status === TaskStatus::COMPLETED && $file->probe->matches($file) => 'green',
    default => 'yellow',
}" />
