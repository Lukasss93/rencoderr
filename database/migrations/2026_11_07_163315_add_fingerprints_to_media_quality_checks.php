<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_quality_checks', function (Blueprint $table): void {
            $table->json('source_fingerprint')->nullable();
            $table->json('output_fingerprint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_quality_checks', fn (Blueprint $table) => $table->dropColumn(['source_fingerprint', 'output_fingerprint']));
    }
};
