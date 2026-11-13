<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite does not index foreign keys. Without these, the latest-transcode lookup and every
 * cascading delete scan the whole child table while holding the database write lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_transcodes', fn (Blueprint $table) => $table->index(['media_file_id', 'id']));
        Schema::table('media_writebacks', function (Blueprint $table): void {
            $table->index('media_quality_check_id');
            $table->index('approved_by');
        });
        Schema::table('transcode_runs', function (Blueprint $table): void {
            $table->index('library_id');
            $table->index('retry_of_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_transcodes', fn (Blueprint $table) => $table->dropIndex(['media_file_id', 'id']));
        Schema::table('media_writebacks', fn (Blueprint $table) => $table->dropIndex(['media_quality_check_id']));
        Schema::table('media_writebacks', fn (Blueprint $table) => $table->dropIndex(['approved_by']));
        Schema::table('transcode_runs', fn (Blueprint $table) => $table->dropIndex(['library_id']));
        Schema::table('transcode_runs', fn (Blueprint $table) => $table->dropIndex(['retry_of_run_id']));
    }
};
