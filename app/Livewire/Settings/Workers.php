<?php

namespace App\Livewire\Settings;

use App\Workers\Enums\WorkerPool;
use App\Workers\HorizonWorkers;
use App\Workers\WorkerLimit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

#[Title('Worker settings')]
class Workers extends Component
{
    use Interactions;

    public string $maxWorkers = '1';

    public string $transcodeMaxWorkers = '1';

    public function mount(WorkerLimit $limit): void
    {
        $this->maxWorkers = (string) $limit->get();
        $this->transcodeMaxWorkers = (string) $limit->get(WorkerPool::TRANSCODES);
    }

    public function save(WorkerLimit $limit): void
    {
        $this->validate(['maxWorkers' => ['required', 'integer', 'min:1']], attributes: ['maxWorkers' => 'maximum workers']);
        $limit->save((int) $this->maxWorkers);
        $this->toast()->success('Worker limit saved.')->send();
    }

    public function saveTranscodes(WorkerLimit $limit): void
    {
        $this->validate(['transcodeMaxWorkers' => ['required', 'integer', 'min:1']], attributes: ['transcodeMaxWorkers' => 'maximum transcode workers']);
        $limit->save((int) $this->transcodeMaxWorkers, WorkerPool::TRANSCODES);
        $this->toast()->success('Transcode worker limit saved.')->send();
    }

    public function render(WorkerLimit $limit, HorizonWorkers $workers): View
    {
        return view('livewire.settings.workers', [
            'savedLimit' => $limit->get(),
            'status' => $workers->status(),
            'transcodeSavedLimit' => $limit->get(WorkerPool::TRANSCODES),
            'transcodeStatus' => $workers->status(WorkerPool::TRANSCODES),
        ])->layoutData(['settingsPage' => true]);
    }
}
