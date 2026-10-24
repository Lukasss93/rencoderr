<?php

namespace App\Livewire\Settings;

use App\Encoding\MediaPreferences;
use App\Livewire\Forms\MediaSettingsForm;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

#[Title('Media settings')]
class Media extends Component
{
    use Interactions;

    public MediaSettingsForm $form;

    public function mount(MediaPreferences $preferences): void
    {
        $this->form->fillFrom($preferences->globalSettings());
    }

    public function applyPreset(): void
    {
        $this->form->applyPreset(false);
    }

    public function save(MediaPreferences $preferences): void
    {
        $this->form->saveGlobal($preferences);
        $this->toast()->success('Media settings saved.')->send();
    }

    public function render(): View
    {
        return view('livewire.settings.media', ['library' => null, 'globalSettings' => null])
            ->layoutData(['settingsPage' => true]);
    }
}
