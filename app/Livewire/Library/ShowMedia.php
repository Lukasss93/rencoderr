<?php

namespace App\Livewire\Library;

use App\Models\MediaFile;
use App\Models\TranscodeRun;
use App\Transcoding\Actions\RequestTranscodeRun;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use TallStackUi\Traits\Interactions;

class ShowMedia extends Component
{
    use Interactions;
    use WithPagination;

    #[Locked]
    public int $mediaFileId;

    public function mount(MediaFile $mediaFile): void
    {
        $this->mediaFileId = $mediaFile->id;
    }

    public function transcode(RequestTranscodeRun $requests): void
    {
        $this->resetValidation('transcode');
        $run = $requests->forFile(MediaFile::query()->findOrFail($this->mediaFileId));
        $this->resetPage();
        $this->toast()->info($run->wasRecentlyCreated && $run->retained_output_count > 0 ? 'An unchanged encoded output already exists.' : ($run->wasRecentlyCreated ? 'Media transcode requested.' : 'This media already has a transcode in the current run.'), 'Review its progress and quality status on the Queue page.')->send();
    }

    public function render(): View
    {
        $file = MediaFile::query()->with(['libraryPath.library', 'probe.streams', 'latestTranscode.qualityCheck'])->findOrFail($this->mediaFileId);

        $root = $file->libraryPath;
        if ($root === null) {
            abort(404);
        }

        return view('livewire.library.show-media', [
            'file' => $file,
            'activeRun' => TranscodeRun::query()->where('library_id', $root->library_id)->active()->first(),
            'transcodes' => $file->transcodes()->with(['run', 'settings', 'qualityCheck'])->latest('id')->paginate(10),
        ])->title(basename($file->relative_path));
    }
}
