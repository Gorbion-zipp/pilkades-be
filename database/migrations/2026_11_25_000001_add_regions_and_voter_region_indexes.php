<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('regions', function (Blueprint $collection) {
            $collection->index('level');
            $collection->index('parent_id');
            $collection->index('kabupaten_id');
            $collection->index('kecamatan_id');
            $collection->index('kelurahan_id');
            $collection->unique(['parent_id', 'level', 'name'], 'regions_parent_level_name_unique');
        });

        Schema::connection('mongodb')->table('voters', function (Blueprint $collection) {
            $collection->index('kabupaten_id');
            $collection->index('kecamatan_id');
            $collection->index('kelurahan_id');
            $collection->index('banjar_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('regions');
    }
};
