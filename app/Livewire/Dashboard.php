<?php

namespace App\Livewire;

use App\Library\Enums\MediaFileStatus;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\TranscodeRun;
use App\Transcoding\Actions\RequestTranscodeRun;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use TallStackUi\Traits\Interactions;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use Interactions;
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
            $this->resetValidation();
        }
    }

    public function transcodeLibrary(RequestTranscodeRun $requests): void
    {
        $this->validate(['library' => ['required', 'integer', 'exists:libraries,id']]);
        $run = $requests->handle(Library::query()->findOrFail((int) $this->library));
        $this->toast()->info($run->wasRecentlyCreated ? 'Library transcode requested.' : 'This library already has an active transcode run.', 'Follow its progress on the Queue page.')->send();
    }

    public function transcodeFile(int $id, RequestTranscodeRun $requests): void
    {
        $this->resetValidation('transcode');
        $run = $requests->forFile(MediaFile::query()->findOrFail($id));
        $this->toast()->info($run->wasRecentlyCreated && $run->retained_output_count > 0 ? 'An unchanged encoded output already exists.' : ($run->wasRecentlyCreated ? 'Media transcode requested.' : 'This media already has a transcode in the current run.'), 'Review its progress and quality status on the Queue page.')->send();
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
            'files' => $this->inventory()->with([
                'libraryPath.library',
                'probe:id,media_file_id,status,source_size_bytes,source_modified_at',
                'latestTranscode:id,media_file_id,status,progress_percent',
                'latestTranscode.qualityCheck',
            ])->orderBy('relative_path')->orderBy('id')->paginate(25),
            'selectedLibrary' => ctype_digit($this->library) ? Library::query()->find((int) $this->library) : null,
            'activeRuns' => TranscodeRun::query()->active()->get(['id', 'library_id'])->keyBy('library_id'),
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
        $status = MediaFileStatus::tryFrom($this->status);

        return MediaFile::query()
            ->when($this->search !== '', fn (Builder $query) => $query->whereRaw(
                "relative_path like ? escape '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->search).'%'],
            ))
            ->when(ctype_digit($this->library), fn (Builder $query) => $query->whereHas(
                'libraryPath',
                fn (Builder $paths) => $paths->where('library_id', (int) $this->library),
            ))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status));
    }
}
