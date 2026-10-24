<?php

use App\Models\Library;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;

function transcodeSource(?Library $library = null): MediaFile
{
    $root = LibraryPath::factory()->for($library ?? Library::factory()->create())->atPath(mediaDirectory(['movie.mkv' => 'video']))->create();
    $modifiedAt = filemtime($root->path.'/movie.mkv');
    if ($modifiedAt === false) {
        throw new RuntimeException('The media fixture is missing.');
    }
    $file = MediaFile::factory()->for($root)->atPath('movie.mkv')->create([
        'size_bytes' => 5, 'filesystem_modified_at' => date('Y-m-d H:i:s', $modifiedAt),
    ]);
    MediaProbe::factory()->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_size_bytes' => $file->size_bytes,
        'source_modified_at' => $file->filesystem_modified_at,
    ]);

    return $file;
}
