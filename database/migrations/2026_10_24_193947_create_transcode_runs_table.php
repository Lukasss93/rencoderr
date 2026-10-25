<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcode_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retry_of_run_id')->nullable()->constrained('transcode_runs');
            $table->uuid('request_token');
            $table->string('status')->default('pending');
            $table->string('library_name');
            $table->unsignedBigInteger('inventory_cutoff_id')->default(0);
            $table->unsignedBigInteger('inventory_cursor_id')->default(0);
            $table->unsignedBigInteger('excluded_metadata_count')->default(0);
            $table->unsignedBigInteger('retained_output_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('enumeration_finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'enumeration_finished_at']);
        });
        DB::statement("CREATE UNIQUE INDEX transcode_runs_active_library_unique ON transcode_runs (library_id) WHERE status IN ('pending', 'queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('transcode_runs');
    }
};
