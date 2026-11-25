<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Voter extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'voters';

    protected $fillable = [
        'nik',
        'name',
        'birth_date',
        'kabupaten_id',
        'kecamatan_id',
        'kelurahan_id',
        'banjar_id',
        'has_voted',
    ];

    protected function casts(): array
    {
        return [
            'has_voted' => 'boolean',
        ];
    }

    /**
     * Bentuk data pemilih yang dikirim ke frontend.
     *
     * @param  array<string, Region>|null  $regionMap
     * @return array<string, mixed>
     */
    public function toApi(?array $regionMap = null): array
    {
        if ($regionMap === null) {
            $ids = array_values(array_filter([
                $this->kabupaten_id,
                $this->kecamatan_id,
                $this->kelurahan_id,
                $this->banjar_id,
            ]));

            $regionMap = $ids === []
                ? []
                : Region::whereIn('_id', $ids)->get()->keyBy(fn (Region $r) => (string) $r->_id)->all();
        }

        return [
            'id' => (string) $this->_id,
            'nik' => $this->nik,
            'name' => $this->name,
            'birth_date' => $this->birth_date,
            'kabupaten_id' => $this->kabupaten_id,
            'kecamatan_id' => $this->kecamatan_id,
            'kelurahan_id' => $this->kelurahan_id,
            'banjar_id' => $this->banjar_id,
            'kabupaten_name' => $this->kabupaten_id && isset($regionMap[$this->kabupaten_id]) ? $regionMap[$this->kabupaten_id]->name : null,
            'kecamatan_name' => $this->kecamatan_id && isset($regionMap[$this->kecamatan_id]) ? $regionMap[$this->kecamatan_id]->name : null,
            'kelurahan_name' => $this->kelurahan_id && isset($regionMap[$this->kelurahan_id]) ? $regionMap[$this->kelurahan_id]->name : null,
            'banjar_name' => $this->banjar_id && isset($regionMap[$this->banjar_id]) ? $regionMap[$this->banjar_id]->name : null,
            'has_voted' => (bool) $this->has_voted,
        ];
    }
}
