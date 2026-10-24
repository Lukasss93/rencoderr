<?php

namespace App\Livewire\Settings;

use App\Encoding\MediaPreferences;
use App\Livewire\Forms\MediaSettingsForm;
use App\Models\Library;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

#[Title('Library media settings')]
class LibraryMedia extends Component
{
    use Interactions;

    #[Locked]
    public int $libraryId;

    public MediaSettingsForm $form;

    public function mount(Library $library, MediaPreferences $preferences): void
    {
        $this->libraryId = $library->id;
        $this->form->fillFrom($preferences->forLibrary($library), array_keys($preferences->overrides($library)));
    }

    public function applyPreset(): void
    {
        $this->form->applyPreset(true);
    }

    public function setOverride(string $field, bool $enabled, MediaPreferences $preferences): void
    {
        $this->form->setOverride($field, $enabled, $preferences->globalSettings());
    }

    public function inheritAll(MediaPreferences $preferences): void
    {
        $this->form->inheritAll($preferences->globalSettings());
    }

    public function save(MediaPreferences $preferences): void
    {
        $this->form->saveLibrary(Library::query()->findOrFail($this->libraryId), $preferences);
        $this->toast()->success('Media settings saved.')->send();
    }

    public function render(MediaPreferences $preferences): View
    {
        return view('livewire.settings.media', [
            'library' => Library::query()->findOrFail($this->libraryId),
            'globalSettings' => $preferences->globalSettings(),
        ])->layoutData(['settingsPage' => true]);
    }
}
