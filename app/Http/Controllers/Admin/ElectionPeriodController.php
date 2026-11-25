<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ElectionPeriodController extends Controller
{
    public function index(): JsonResponse
    {
        $periods = ElectionPeriod::orderBy('year', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $counts = [];
        foreach (Election::all(['period_id']) as $election) {
            $pid = (string) $election->period_id;
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }

        return response()->json([
            'periods' => $periods->map(fn (ElectionPeriod $p) => $p->toApi($counts[(string) $p->_id] ?? 0))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2000|max:2100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => ['nullable', Rule::in(ElectionPeriod::STATUSES)],
        ]);

        $status = $data['status'] ?? ElectionPeriod::STATUS_DRAFT;

        if ($status === ElectionPeriod::STATUS_ACTIVE) {
            $this->deactivateOtherPeriods();
        }

        $period = ElectionPeriod::create([
            'name' => trim($data['name']),
            'year' => (int) $data['year'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $status,
        ]);

        return response()->json([
            'message' => 'Periode pemilihan berhasil dibuat.',
            'period' => $period->toApi(0),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $period = ElectionPeriod::find($id);

        if (! $period) {
            return response()->json(['message' => 'Periode pemilihan tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2000|max:2100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => ['nullable', Rule::in(ElectionPeriod::STATUSES)],
        ]);

        $status = $data['status'] ?? $period->status ?? ElectionPeriod::STATUS_DRAFT;

        if ($status === ElectionPeriod::STATUS_ACTIVE) {
            $this->deactivateOtherPeriods((string) $period->_id);
        }

        $period->update([
            'name' => trim($data['name']),
            'year' => (int) $data['year'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $status,
        ]);

        $count = Election::where('period_id', (string) $period->_id)->count();

        return response()->json([
            'message' => 'Periode pemilihan berhasil diperbarui.',
            'period' => $period->fresh()->toApi($count),
        ]);
    }

    public function activate(string $id): JsonResponse
    {
        $period = ElectionPeriod::find($id);

        if (! $period) {
            return response()->json(['message' => 'Periode pemilihan tidak ditemukan.'], 404);
        }

        $this->deactivateOtherPeriods((string) $period->_id);

        $period->update(['status' => ElectionPeriod::STATUS_ACTIVE]);
        $count = Election::where('period_id', (string) $period->_id)->count();

        return response()->json([
            'message' => 'Periode pemilihan diaktifkan.',
            'period' => $period->fresh()->toApi($count),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $period = ElectionPeriod::find($id);

        if (! $period) {
            return response()->json(['message' => 'Periode pemilihan tidak ditemukan.'], 404);
        }

        if (Election::where('period_id', (string) $period->_id)->exists()) {
            return response()->json([
                'message' => 'Periode tidak dapat dihapus karena sudah memiliki data pemilihan.',
            ], 422);
        }

        $period->delete();

        return response()->json(['message' => 'Periode pemilihan berhasil dihapus.']);
    }

    private function deactivateOtherPeriods(?string $exceptId = null): void
    {
        $query = ElectionPeriod::where('status', ElectionPeriod::STATUS_ACTIVE);

        if ($exceptId !== null) {
            $query->where('_id', '!=', $exceptId);
        }

        $query->update(['status' => ElectionPeriod::STATUS_ARCHIVED]);
    }
}
