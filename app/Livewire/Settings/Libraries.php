<?php

namespace App\Livewire\Settings;

use App\Library\Actions\RequestLibraryScan;
use App\Library\Actions\SaveLibrary;
use App\Library\DirectoryBrowser;
use App\Library\LibraryPathResolver;
use App\Livewire\Forms\LibraryForm;
use App\Models\Library;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

#[Title('Library settings')]
class Libraries extends Component
{
    use Interactions;

    public bool $showEditor = false;

    #[Locked]
    public ?int $editingId = null;

    public LibraryForm $form;

    public bool $showBrowser = false;

    public string $browserPath = '/';

    /**
     * Read from disk only when navigating, not on every render, because the page refreshes
     * itself every few seconds.
     *
     * @var array{path: string, parent: ?string, breadcrumbs: list<array{name: string, path: string}>, directories: list<array{name: string, path: string}>, total: int, page: int, last_page: int}|null
     */
    #[Locked]
    public ?array $listing = null;

    public function create(): void
    {
        $this->reset('editingId', 'showBrowser', 'listing');
        $this->form->reset();
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $library = Library::query()->with('paths')->findOrFail($id);
        $this->create();
        $this->editingId = $library->id;
        $this->form->fillFrom($library);
    }

    public function addPath(): void
    {
        $this->form->addPath();
    }

    public function removePath(int $index): void
    {
        $this->form->removePath($index);
    }

    public function save(SaveLibrary $saveLibrary): void
    {
        $this->form->save($this->editingId, $saveLibrary);
        $this->showEditor = false;
        $this->showBrowser = false;
        $this->toast()->success('Library saved.')->send();
    }

    public function delete(int $id): void
    {
        Library::query()->findOrFail($id)->delete();
        $this->toast()->success('Library removed.', 'Source files were left unchanged.')->send();
    }

    public function scan(int $id, RequestLibraryScan $requests): void
    {
        $count = $requests->handle(Library::query()->findOrFail($id));
        $this->toast()->info($count === 0 ? 'All folders are already queued or scanning.' : 'Queued '.$count.' folder scans.')->send();
    }

    public function browse(DirectoryBrowser $browser): void
    {
        $this->navigate($browser, '/');
        $this->showBrowser = true;
    }

    public function navigate(DirectoryBrowser $browser, ?string $path = null, int $page = 1): void
    {
        $this->listing = $browser->browse($path ?? $this->browserPath, $page);
        $this->browserPath = $this->listing['path'];
        $this->resetValidation('browserPath');
    }

    public function selectDirectory(LibraryPathResolver $paths): void
    {
        $this->form->paths[] = $paths->canonical($this->listing['path'] ?? '', 'browserPath');
        $this->showBrowser = false;
    }

    public function render(): View
    {
        return view('livewire.settings.libraries', [
            'libraries' => Library::query()->with('paths')->orderBy('name')->orderBy('id')->get(),
        ])->layoutData(['settingsPage' => true]);
    }
}
