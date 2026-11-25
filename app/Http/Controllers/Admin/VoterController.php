<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Voter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nik' => ['required', 'digits:16', Rule::unique('mongodb.voters', 'nik')],
            'name' => 'required|string|max:255',
            'birth_date' => 'required|date_format:Y-m-d',
            'banjar_id' => 'required|string',
        ]);

        $banjar = Region::find($data['banjar_id']);

        if (! $banjar || $banjar->level !== Region::LEVEL_BANJAR) {
            return response()->json([
                'message' => 'Banjar yang dipilih tidak valid.',
                'errors' => ['banjar_id' => ['Banjar yang dipilih tidak valid.']],
            ], 422);
        }

        $voter = Voter::create([
            'nik' => $data['nik'],
            'name' => $data['name'],
            'birth_date' => $data['birth_date'],
            'kabupaten_id' => $banjar->kabupaten_id,
            'kecamatan_id' => $banjar->kecamatan_id,
            'kelurahan_id' => $banjar->kelurahan_id,
            'banjar_id' => (string) $banjar->_id,
            'has_voted' => false,
        ]);

        return response()->json([
            'message' => 'Pemilih berhasil ditambahkan.',
            'voter' => $voter->toApi(),
        ], 201);
    }

    public function resetStatus(string $id): JsonResponse
    {
        $voter = Voter::find($id);

        if (! $voter) {
            return response()->json(['message' => 'Data pemilih tidak ditemukan.'], 404);
        }

        $voter->has_voted = false;
        $voter->save();

        return response()->json(['message' => 'Status voting pemilih berhasil direset.']);
    }

    public function destroy(string $id): JsonResponse
    {
        $voter = Voter::find($id);

        if (! $voter) {
            return response()->json(['message' => 'Data pemilih tidak ditemukan.'], 404);
        }

        $voter->delete();

        return response()->json(['message' => 'Data pemilih berhasil dihapus.']);
    }
}
