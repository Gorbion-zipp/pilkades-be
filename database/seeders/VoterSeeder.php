<?php

namespace Database\Seeders;

use App\Models\Region;
use App\Models\Voter;
use Illuminate\Database\Seeder;

class VoterSeeder extends Seeder
{
    public function run(): void
    {
        $banjarCanggu = Region::where('level', Region::LEVEL_BANJAR)->where('name', 'Banjar Canggu')->first();
        $banjarPipitan = Region::where('level', Region::LEVEL_BANJAR)->where('name', 'Banjar Pipitan')->first();
        $banjarTegal = Region::where('level', Region::LEVEL_BANJAR)->where('name', 'Banjar Tegal Gundul')->first();

        $voters = [
            ['nik' => '5103010101900001', 'name' => 'I Wayan Sudiarta', 'birth_date' => '1990-01-01', 'banjar' => $banjarCanggu],
            ['nik' => '5103011503880002', 'name' => 'Ni Luh Putu Anggreni', 'birth_date' => '1988-03-15', 'banjar' => $banjarCanggu],
            ['nik' => '5103012207950003', 'name' => 'I Made Gede Pratama', 'birth_date' => '1995-07-22', 'banjar' => $banjarPipitan],
            ['nik' => '5103010911020004', 'name' => 'Ni Kadek Ayu Lestari', 'birth_date' => '2002-11-09', 'banjar' => $banjarPipitan],
            ['nik' => '5103013004990005', 'name' => 'I Ketut Bagus Mahendra', 'birth_date' => '1999-04-30', 'banjar' => $banjarTegal],
        ];

        foreach ($voters as $voter) {
            $banjar = $voter['banjar'];

            Voter::updateOrCreate(
                ['nik' => $voter['nik']],
                [
                    'name' => $voter['name'],
                    'birth_date' => $voter['birth_date'],
                    'kabupaten_id' => $banjar?->kabupaten_id,
                    'kecamatan_id' => $banjar?->kecamatan_id,
                    'kelurahan_id' => $banjar?->kelurahan_id,
                    'banjar_id' => $banjar ? (string) $banjar->_id : null,
                    'has_voted' => false,
                ]
            );
        }
    }
}
