<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->convertFingerprints(true);
        Schema::table('media_writebacks', function (Blueprint $table): void {
            $table->dropColumn('candidate_identity');
        });
    }

    public function down(): void
    {
        Schema::table('media_writebacks', function (Blueprint $table): void {
            $table->json('candidate_identity')->nullable();
        });
        $this->convertFingerprints(false);
    }

    private function convertFingerprints(bool $strings): void
    {
        foreach (['media_quality_checks', 'media_writebacks'] as $table) {
            foreach (DB::table($table)->select(['id', 'source_fingerprint', 'output_fingerprint'])->lazyById(200) as $row) {
                $values = [];
                foreach (['source_fingerprint', 'output_fingerprint'] as $column) {
                    $fingerprint = json_decode($row->{$column} ?? 'null', true, flags: JSON_THROW_ON_ERROR);
                    $hash = is_array($fingerprint) ? $fingerprint['sha256'] : $fingerprint;
                    $values[$column] = $hash === null ? null
                        : json_encode($strings ? $hash : ['sha256' => $hash], JSON_THROW_ON_ERROR);
                }
                DB::table($table)->where('id', $row->id)->update($values);
            }
        }
    }
};
