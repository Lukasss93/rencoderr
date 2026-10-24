<?php

use App\Jobs\ProbeMediaFile;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Queue;

it('allows an explicit request for present files and leaves active requests alone', function (): void {
    Queue::fake([ProbeMediaFile::class]);
    $file = MediaFile::factory()->create();

    artisanCommand('media:probe', ['fileId' => $file->id])->assertSuccessful();
    $token = $file->probe()->sole()->request_token;
    artisanCommand('media:probe', ['fileId' => $file->id])->assertSuccessful();

    expect($file->probe()->sole()->request_token)->toBe($token);
    Queue::assertPushed(ProbeMediaFile::class, 1);
    $file->update(['status' => 'missing']);
    artisanCommand('media:probe', ['fileId' => $file->id])->assertFailed();
    artisanCommand('media:probe', ['fileId' => 'invalid'])->assertFailed();
    artisanCommand('media:probe', ['fileId' => 999999])->assertFailed();
});
