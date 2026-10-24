<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settingable_id')->nullable();
            $table->string('settingable_type')->nullable();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['settingable_type', 'settingable_id', 'key']);
        });

        DB::statement('CREATE UNIQUE INDEX settings_global_key_unique ON settings ("key") WHERE settingable_type IS NULL AND settingable_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
