<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table): void {
            $table->string('writeback_mode')->default('approval');
        });
        Schema::table('media_transcodes', function (Blueprint $table): void {
            $table->string('writeback_mode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_transcodes', fn (Blueprint $table) => $table->dropColumn('writeback_mode'));
        Schema::table('libraries', fn (Blueprint $table) => $table->dropColumn('writeback_mode'));
    }
};
