<?php

namespace App\Livewire;

use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\CancelTranscodeRun;
use App\Transcoding\Actions\RequestTranscodeRun;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use TallStackUi\Traits\Interactions;

#[Title('Queue')]
class Queue extends Component
{
    use Interactions;
    use WithPagination;

    public function cancel(int $id, CancelTranscodeRun $cancel): void
    {
        $cancel->handle(TranscodeRun::query()->findOrFail($id));
        $this->toast()->info('Run cancellation requested.')->send();
    }

    public function retryFailed(int $id, RequestTranscodeRun $requests): void
    {
        $requests->retryFailed(TranscodeRun::query()->findOrFail($id));
        $this->resetPage('runsPage');
        $this->resetPage('filesPage');
        $this->toast()->info('Failed transcodes requested again.')->send();
    }

    public function render(): View
    {
        return view('livewire.queue', [
            'runs' => TranscodeRun::query()->withCount([
                'transcodes',
                'transcodes as skipped_count' => fn ($query) => $query->where('status', TaskStatus::SKIPPED),
                'transcodes as completed_count' => fn ($query) => $query->where('status', TaskStatus::COMPLETED),
                'transcodes as failed_count' => fn ($query) => $query->where('status', TaskStatus::FAILED),
            ])->latest('id')->paginate(10, pageName: 'runsPage'),
            'transcodes' => MediaTranscode::query()->with('run')->latest('id')->paginate(25, pageName: 'filesPage'),
        ]);
    }
}
