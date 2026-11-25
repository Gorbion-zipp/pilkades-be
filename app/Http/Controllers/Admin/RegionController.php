<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Voter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allRegions = Region::orderBy('name')->get();
        $regionMap = $allRegions->keyBy(fn (Region $r) => (string) $r->_id)->all();

        $childCounts = [];
        foreach ($allRegions as $r) {
            if ($r->parent_id) {
                $childCounts[$r->parent_id] = ($childCounts[$r->parent_id] ?? 0) + 1;
            }
        }

        $filtered = $allRegions->filter(function (Region $region) use ($request) {
            if ($request->filled('level') && $region->level !== $request->string('level')->toString()) {
                return false;
            }
            if ($request->has('parent_id') && $request->input('parent_id') !== '') {
                if ($region->parent_id !== $request->input('parent_id')) {
                    return false;
                }
            }
            if ($request->filled('kabupaten_id') && $region->kabupaten_id !== $request->input('kabupaten_id')) {
                return false;
            }
            if ($request->filled('kecamatan_id') && $region->kecamatan_id !== $request->input('kecamatan_id')) {
                return false;
            }
            if ($request->filled('kelurahan_id') && $region->kelurahan_id !== $request->input('kelurahan_id')) {
                return false;
            }
            if ($request->filled('search')) {
                $q = mb_strtolower($request->string('search')->toString());
                if (! str_contains(mb_strtolower((string) $region->name), $q)) {
                    return false;
                }
            }

            return true;
        });

        $items = $filtered->values()->map(function (Region $region) use ($regionMap, $childCounts) {
            $item = $region->toApi($regionMap);
            $item['children_count'] = $childCounts[(string) $region->_id] ?? 0;

            return $item;
        })->all();

        return response()->json([
            'regions' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'level' => ['required', Rule::in(Region::LEVELS)],
            'parent_id' => 'nullable|string',
            'code' => 'nullable|string|max:20',
        ]);

        $level = $data['level'];
        $name = trim(preg_replace('/\s+/', ' ', $data['name']) ?? $data['name']);
        $expectedParentLevel = Region::expectedParentLevel($level);
        $parentId = isset($data['parent_id']) && trim((string) $data['parent_id']) !== '' ? trim((string) $data['parent_id']) : null;

        $kabupatenId = null;
        $kecamatanId = null;
        $kelurahanId = null;

        if ($expectedParentLevel === null) {
            if ($parentId !== null) {
                return response()->json(['message' => 'Kabupaten / Kota tidak boleh memiliki wilayah induk.'], 422);
            }
        } else {
            if ($parentId === null) {
                return response()->json([
                    'message' => 'Wilayah induk ('.Region::levelLabel($expectedParentLevel).') wajib dipilih.',
                ], 422);
            }

            $parent = Region::find($parentId);

            if (! $parent || $parent->level !== $expectedParentLevel) {
                return response()->json([
                    'message' => 'Wilayah induk tidak valid. Harus berupa '.Region::levelLabel($expectedParentLevel).'.',
                ], 422);
            }

            if ($level === Region::LEVEL_KECAMATAN) {
                $kabupatenId = (string) $parent->_id;
            } elseif ($level === Region::LEVEL_KELURAHAN) {
                $kabupatenId = $parent->kabupaten_id;
                $kecamatanId = (string) $parent->_id;
            } elseif ($level === Region::LEVEL_BANJAR) {
                $kabupatenId = $parent->kabupaten_id;
                $kecamatanId = $parent->kecamatan_id;
                $kelurahanId = (string) $parent->_id;
            }
        }

        $duplicate = Region::where('parent_id', $parentId)
            ->where('level', $level)
            ->get()
            ->first(fn (Region $r) => mb_strtolower((string) $r->name) === mb_strtolower($name));

        if ($duplicate) {
            return response()->json([
                'message' => Region::levelLabel($level).' "'.$name.'" sudah terdaftar pada wilayah induk ini.',
            ], 422);
        }

        $region = Region::create([
            'name' => $name,
            'level' => $level,
            'parent_id' => $parentId,
            'code' => isset($data['code']) && trim((string) $data['code']) !== '' ? trim((string) $data['code']) : null,
            'kabupaten_id' => $kabupatenId,
            'kecamatan_id' => $kecamatanId,
            'kelurahan_id' => $kelurahanId,
        ]);

        return response()->json([
            'message' => Region::levelLabel($level).' berhasil ditambahkan.',
            'region' => $region->toApi(),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $region = Region::find($id);

        if (! $region) {
            return response()->json(['message' => 'Data wilayah tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'nullable|string|max:20',
        ]);

        $name = trim(preg_replace('/\s+/', ' ', $data['name']) ?? $data['name']);

        $duplicate = Region::where('parent_id', $region->parent_id)
            ->where('level', $region->level)
            ->where('_id', '!=', $region->_id)
            ->get()
            ->first(fn (Region $r) => mb_strtolower((string) $r->name) === mb_strtolower($name));

        if ($duplicate) {
            return response()->json([
                'message' => Region::levelLabel((string) $region->level).' "'.$name.'" sudah ada pada wilayah induk ini.',
            ], 422);
        }

        $region->update([
            'name' => $name,
            'code' => isset($data['code']) && trim((string) $data['code']) !== '' ? trim((string) $data['code']) : null,
        ]);

        return response()->json([
            'message' => 'Data wilayah berhasil diperbarui.',
            'region' => $region->toApi(),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $region = Region::find($id);

        if (! $region) {
            return response()->json(['message' => 'Data wilayah tidak ditemukan.'], 404);
        }

        if (Region::where('parent_id', $id)->exists()) {
            return response()->json([
                'message' => 'Wilayah tidak dapat dihapus karena masih memiliki sub-wilayah di bawahnya.',
            ], 422);
        }

        $hasVoters = Voter::where('kabupaten_id', $id)
            ->orWhere('kecamatan_id', $id)
            ->orWhere('kelurahan_id', $id)
            ->orWhere('banjar_id', $id)
            ->exists();

        if ($hasVoters) {
            return response()->json([
                'message' => 'Wilayah tidak dapat dihapus karena masih terhubung dengan data DPT.',
            ], 422);
        }

        $region->delete();

        return response()->json(['message' => 'Data wilayah berhasil dihapus.']);
    }
}
