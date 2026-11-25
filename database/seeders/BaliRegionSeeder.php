<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

class BaliRegionSeeder extends Seeder
{
    /**
     * Seeder data wilayah administratif Provinsi Bali:
     * Kabupaten/Kota -> Kecamatan -> Kelurahan/Desa -> Banjar/Lingkungan.
     */
    public function run(): void
    {
        $tree = [
            'Kabupaten Badung' => [
                'code' => '5103',
                'kecamatan' => [
                    'Kecamatan Kuta Utara' => [
                        'Desa Canggu' => ['Banjar Canggu', 'Banjar Pipitan', 'Banjar Tegal Gundul', 'Banjar Padang Linjong'],
                        'Desa Tibubeneng' => ['Banjar Berawa', 'Banjar Tandeg', 'Banjar Tegal Gundul Selatan'],
                        'Kelurahan Kerobokan' => ['Banjar Taman', 'Banjar Semer', 'Banjar Dukuh Sari'],
                    ],
                    'Kecamatan Mengwi' => [
                        'Desa Mengwitani' => ['Banjar Mengwitani', 'Banjar Pupuan', 'Banjar Sila Dharma'],
                        'Kelurahan Kapal' => ['Banjar Cepaka', 'Banjar Titih', 'Banjar Belulang'],
                        'Desa Sembung' => ['Banjar Sembung', 'Banjar Karangjung'],
                    ],
                    'Kecamatan Abiansemal' => [
                        'Desa Sangeh' => ['Banjar Brahmana', 'Banjar Muluk Babi', 'Banjar Pacung'],
                        'Desa Sibang Kaja' => ['Banjar Lambing', 'Banjar Saren', 'Banjar Tengah'],
                    ],
                    'Kecamatan Kuta' => [
                        'Kelurahan Kuta' => ['Banjar Pande Mas', 'Banjar Pengabetan', 'Banjar Temacun'],
                        'Kelurahan Seminyak' => ['Banjar Seminyak', 'Banjar Basangkasa'],
                    ],
                    'Kecamatan Kuta Selatan' => [
                        'Kelurahan Jimbaran' => ['Banjar Menega', 'Banjar Tegal', 'Banjar Ubung'],
                        'Desa Pecatu' => ['Banjar Suluban', 'Banjar Kangin', 'Banjar Kauh'],
                    ],
                    'Kecamatan Petang' => [
                        'Desa Petang' => ['Banjar Petang Kaja', 'Banjar Petang Kelod'],
                        'Desa Carangsari' => ['Banjar Pemijian', 'Banjar Anggungan'],
                    ],
                ],
            ],
            'Kota Denpasar' => [
                'code' => '5171',
                'kecamatan' => [
                    'Kecamatan Denpasar Selatan' => [
                        'Kelurahan Sanur' => ['Banjar Semawang', 'Banjar Batujimbar', 'Banjar Pasek'],
                        'Kelurahan Renon' => ['Banjar Kelod Renon', 'Banjar Tengah Renon', 'Banjar Pande'],
                        'Desa Sanur Kauh' => ['Banjar Pekandelan', 'Banjar Medura'],
                    ],
                    'Kecamatan Denpasar Barat' => [
                        'Kelurahan Pemecutan' => ['Banjar Kerandan', 'Banjar Pemedilan'],
                        'Desa Dauh Puri Kangin' => ['Banjar Titih', 'Banjar Suci'],
                    ],
                    'Kecamatan Denpasar Timur' => [
                        'Kelurahan Kesiman' => ['Banjar Kedaton', 'Banjar Abian Tubuh'],
                        'Desa Kesiman Kertalangu' => ['Banjar Tohpati', 'Banjar Kertalangu'],
                    ],
                    'Kecamatan Denpasar Utara' => [
                        'Kelurahan Peguyangan' => ['Banjar Kepuh', 'Banjar Tektek'],
                        'Desa Dangin Puri Kaja' => ['Banjar Mekar Sari', 'Banjar Lumintang'],
                    ],
                ],
            ],
            'Kabupaten Gianyar' => [
                'code' => '5104',
                'kecamatan' => [
                    'Kecamatan Ubud' => [
                        'Kelurahan Ubud' => ['Banjar Ubud Tengah', 'Banjar Padang Tegal', 'Banjar Sambahan'],
                        'Desa Sayan' => ['Banjar Kutuh', 'Banjar Penestanan'],
                    ],
                    'Kecamatan Sukawati' => [
                        'Desa Sukawati' => ['Banjar Gelumpang', 'Banjar Palak'],
                        'Desa Batubulan' => ['Banjar Tegeha', 'Banjar Tegal Jaya'],
                    ],
                    'Kecamatan Gianyar' => [
                        'Kelurahan Gianyar' => ['Banjar Sengguan', 'Banjar Sangging'],
                    ],
                    'Kecamatan Blahbatuh' => [
                        'Desa Blahbatuh' => ['Banjar Babakan', 'Banjar Kebon'],
                    ],
                    'Kecamatan Tampaksiring' => [
                        'Desa Tampaksiring' => ['Banjar Bukit', 'Banjar Tengah'],
                    ],
                    'Kecamatan Tegallalang' => [
                        'Desa Tegallalang' => ['Banjar Tegallalang', 'Banjar Pejeng Aji'],
                    ],
                    'Kecamatan Payangan' => [
                        'Desa Melinggih' => ['Banjar Melinggih', 'Banjar Badung'],
                    ],
                ],
            ],
            'Kabupaten Tabanan' => [
                'code' => '5102',
                'kecamatan' => [
                    'Kecamatan Tabanan' => [
                        'Desa Dajan Peken' => ['Banjar Jambe', 'Banjar Kamasan'],
                        'Desa Delod Peken' => ['Banjar Pande', 'Banjar Gerokgak'],
                    ],
                    'Kecamatan Kediri' => [
                        'Desa Kediri' => ['Banjar Puseh', 'Banjar Demung'],
                        'Desa Beraban' => ['Banjar Beraban', 'Banjar Nyanyi'],
                    ],
                    'Kecamatan Kerambitan' => [],
                    'Kecamatan Marga' => [],
                    'Kecamatan Penebel' => [],
                    'Kecamatan Baturiti' => [],
                    'Kecamatan Selemadeg' => [],
                    'Kecamatan Selemadeg Timur' => [],
                    'Kecamatan Selemadeg Barat' => [],
                    'Kecamatan Pupuan' => [],
                ],
            ],
            'Kabupaten Buleleng' => [
                'code' => '5108',
                'kecamatan' => [
                    'Kecamatan Buleleng' => [
                        'Kelurahan Banyuasri' => ['Lingkungan Banyuasri Barat', 'Lingkungan Banyuasri Timur'],
                        'Kelurahan Paket Agung' => ['Lingkungan Paket Agung'],
                    ],
                    'Kecamatan Sukasada' => [],
                    'Kecamatan Banjar' => [],
                    'Kecamatan Seririt' => [],
                    'Kecamatan Busungbiu' => [],
                    'Kecamatan Gerokgak' => [],
                    'Kecamatan Kubutambahan' => [],
                    'Kecamatan Sawan' => [],
                    'Kecamatan Tejakula' => [],
                ],
            ],
            'Kabupaten Karangasem' => [
                'code' => '5107',
                'kecamatan' => [
                    'Kecamatan Karangasem' => [
                        'Kelurahan Karangasem' => ['Lingkungan Jasri', 'Lingkungan Padangkerta'],
                    ],
                    'Kecamatan Abang' => [],
                    'Kecamatan Bebandem' => [],
                    'Kecamatan Kubu' => [],
                    'Kecamatan Manggis' => [],
                    'Kecamatan Rendang' => [],
                    'Kecamatan Selat' => [],
                    'Kecamatan Sidemen' => [],
                ],
            ],
            'Kabupaten Klungkung' => [
                'code' => '5105',
                'kecamatan' => [
                    'Kecamatan Klungkung' => [
                        'Kelurahan Semarapura Tengah' => ['Lingkungan Pegending', 'Lingkungan Besang'],
                    ],
                    'Kecamatan Banjarangkan' => [],
                    'Kecamatan Dawan' => [],
                    'Kecamatan Nusa Penida' => [
                        'Desa Ped' => ['Banjar Ped', 'Banjar Bodong'],
                    ],
                ],
            ],
            'Kabupaten Bangli' => [
                'code' => '5106',
                'kecamatan' => [
                    'Kecamatan Bangli' => [
                        'Kelurahan Kawan' => ['Lingkungan Kawan', 'Lingkungan Tegallalang'],
                    ],
                    'Kecamatan Susut' => [],
                    'Kecamatan Tembuku' => [],
                    'Kecamatan Kintamani' => [
                        'Desa Kintamani' => ['Banjar Kintamani', 'Banjar Glagahlinggah'],
                    ],
                ],
            ],
            'Kabupaten Jembrana' => [
                'code' => '5101',
                'kecamatan' => [
                    'Kecamatan Negara' => [
                        'Kelurahan Loloan Timur' => ['Lingkungan Loloan Timur'],
                    ],
                    'Kecamatan Jembrana' => [],
                    'Kecamatan Melaya' => [],
                    'Kecamatan Mendoyo' => [],
                    'Kecamatan Pekutatan' => [],
                ],
            ],
        ];

        foreach ($tree as $kabName => $kabData) {
            $kab = Region::updateOrCreate(
                [
                    'parent_id' => null,
                    'level' => Region::LEVEL_KABUPATEN,
                    'name' => $kabName,
                ],
                [
                    'code' => $kabData['code'],
                    'kabupaten_id' => null,
                    'kecamatan_id' => null,
                    'kelurahan_id' => null,
                ]
            );
            $kabId = (string) $kab->_id;

            foreach ($kabData['kecamatan'] as $kecName => $kelurahans) {
                $kec = Region::updateOrCreate(
                    [
                        'parent_id' => $kabId,
                        'level' => Region::LEVEL_KECAMATAN,
                        'name' => $kecName,
                    ],
                    [
                        'code' => null,
                        'kabupaten_id' => $kabId,
                        'kecamatan_id' => null,
                        'kelurahan_id' => null,
                    ]
                );
                $kecId = (string) $kec->_id;

                foreach ($kelurahans as $kelName => $banjars) {
                    $kel = Region::updateOrCreate(
                        [
                            'parent_id' => $kecId,
                            'level' => Region::LEVEL_KELURAHAN,
                            'name' => $kelName,
                        ],
                        [
                            'code' => null,
                            'kabupaten_id' => $kabId,
                            'kecamatan_id' => $kecId,
                            'kelurahan_id' => null,
                        ]
                    );
                    $kelId = (string) $kel->_id;

                    foreach ($banjars as $banjarName) {
                        Region::updateOrCreate(
                            [
                                'parent_id' => $kelId,
                                'level' => Region::LEVEL_BANJAR,
                                'name' => $banjarName,
                            ],
                            [
                                'code' => null,
                                'kabupaten_id' => $kabId,
                                'kecamatan_id' => $kecId,
                                'kelurahan_id' => $kelId,
                            ]
                        );
                    }
                }
            }
        }
    }
}
