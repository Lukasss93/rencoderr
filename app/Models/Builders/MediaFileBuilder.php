<?php

namespace App\Models\Builders;

use App\Library\Enums\MediaFileStatus;
use App\Models\MediaFile;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<MediaFile> */
class MediaFileBuilder extends Builder
{
    public function search(string $text): static
    {
        if ($text === '') {
            return $this;
        }

        return $this->whereRaw("relative_path like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%']);
    }

    public function inLibrary(?int $libraryId): static
    {
        if ($libraryId === null) {
            return $this;
        }

        return $this->whereHas('libraryPath', fn (Builder $paths) => $paths->where('library_id', $libraryId));
    }

    public function withStatus(?MediaFileStatus $status): static
    {
        return $status === null ? $this : $this->where('status', $status);
    }
}
