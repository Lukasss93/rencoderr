<?php

namespace App\Livewire\Settings;

use App\Settings\ApplicationSettings;
use App\Settings\HorizonWorkers;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Worker settings')]
class Workers extends Component
{
    public string $maxWorkers = '1';

    public string $notice = '';

    public function mount(ApplicationSettings $settings): void
    {
        $this->maxWorkers = (string) $settings->queueMaxWorkers();
    }

    public function save(ApplicationSettings $settings): void
    {
        $this->notice = '';
        $this->resetValidation();
        $settings->saveQueueMaxWorkers($this->maxWorkers);
        $this->notice = 'Worker limit saved.';
    }

    public function render(): View
    {
        return view('livewire.settings.workers', [
            'savedLimit' => app(ApplicationSettings::class)->queueMaxWorkers(),
            'runtime' => app(HorizonWorkers::class)->status(),
        ])->layoutData(['settingsPage' => true]);
    }
}
