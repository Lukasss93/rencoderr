<?php

namespace App\Livewire;

use App\Library\Enums\MediaFileStatus;
use App\Models\Library;
use App\Models\MediaFile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $library = '';

    #[Url]
    public string $status = '';

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'library', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $totals = $this->inventory()->toBase()
            ->selectRaw('status, count(*) as files, sum(size_bytes) as bytes')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        $counts = [];

        foreach (MediaFileStatus::cases() as $status) {
            $counts[$status->value] = (int) ($totals[$status->value]->files ?? 0);
        }

        return view('livewire.dashboard', [
            'files' => $this->inventory()->with('libraryPath.library')->orderBy('relative_path')->orderBy('id')->paginate(25),
            'counts' => $counts,
            'presentSize' => Number::fileSize((int) ($totals[MediaFileStatus::PRESENT->value]->bytes ?? 0), maxPrecision: 2),
            'libraryOptions' => [
                ['label' => 'All libraries', 'value' => ''],
                ...Library::query()->orderBy('name')->orderBy('id')->get()
                    ->map(fn (Library $library): array => ['label' => $library->name, 'value' => (string) $library->id])->all(),
            ],
            'statusOptions' => [
                ['label' => 'All statuses', 'value' => ''],
                ...array_map(fn (MediaFileStatus $status): array => ['label' => ucfirst($status->value), 'value' => $status->value], MediaFileStatus::cases()),
            ],
        ]);
    }

    /** @return Builder<MediaFile> */
    private function inventory(): Builder
    {
        return MediaFile::query()
            ->search($this->search)
            ->inLibrary(ctype_digit($this->library) ? (int) $this->library : null)
            ->withStatus(MediaFileStatus::tryFrom($this->status));
    }
}
