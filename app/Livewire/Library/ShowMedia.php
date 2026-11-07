<?php

namespace App\Livewire\Library;

use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\MediaWriteback;
use App\Models\TranscodeRun;
use App\Transcoding\Actions\RequestTranscodeRun;
use App\Writeback\Actions\ApproveWriteback;
use App\Writeback\Actions\RetryWriteback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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

    #[Locked]
    public ?int $selectedWritebackId = null;

    public bool $showReplacementReview = false;

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

    public function reviewReplacement(int $transcodeId): void
    {
        $this->resetValidation('writeback');
        $transcode = MediaTranscode::query()->where('media_file_id', $this->mediaFileId)
            ->with('writeback.qualityCheck')->findOrFail($transcodeId);
        $writeback = $transcode->writeback;
        if ($writeback === null || ! $writeback->awaitsApproval()) {
            throw ValidationException::withMessages(['writeback' => 'This result is not awaiting replacement approval.']);
        }

        $this->selectedWritebackId = $writeback->id;
        $this->showReplacementReview = true;
    }

    public function replaceOriginal(ApproveWriteback $approval): void
    {
        if ($this->selectedWritebackId === null) {
            return;
        }

        $approval->handle($this->selectedWriteback(), Auth::user()?->id);
        $this->reset('selectedWritebackId', 'showReplacementReview');
        $this->toast()->info('Original replacement queued.', 'Follow its progress in the transcode history.')->send();
    }

    public function retryWriteback(int $writebackId, RetryWriteback $retry): void
    {
        $writeback = MediaWriteback::query()
            ->whereHas('transcode', fn ($query) => $query->where('media_file_id', $this->mediaFileId))
            ->findOrFail($writebackId);
        $retry->handle($writeback);
        $this->resetValidation('writeback');
        $this->toast()->info('Write-back queued again.')->send();
    }

    public function render(): View
    {
        $file = MediaFile::query()->with(['libraryPath.library', 'probe.streams', 'latestTranscode.qualityCheck', 'latestTranscode.writeback.qualityCheck'])->findOrFail($this->mediaFileId);

        $root = $file->libraryPath;
        if ($root === null) {
            abort(404);
        }

        $reviewWriteback = $this->selectedWritebackId === null ? null : $this->selectedWriteback();

        return view('livewire.library.show-media', [
            'file' => $file,
            'activeRun' => TranscodeRun::query()->where('library_id', $root->library_id)->active()->first(),
            'transcodes' => $file->transcodes()->with(['run', 'settings', 'qualityCheck', 'writeback.qualityCheck'])->latest('id')->paginate(10),
            'reviewWriteback' => $reviewWriteback,
            'canApproveReview' => $reviewWriteback !== null && $reviewWriteback->awaitsApproval(),
        ])->title(basename($file->relative_path));
    }

    private function selectedWriteback(): MediaWriteback
    {
        return MediaWriteback::query()->with('qualityCheck')
            ->whereHas('transcode', fn ($query) => $query->where('media_file_id', $this->mediaFileId))
            ->findOrFail($this->selectedWritebackId);
    }
}
