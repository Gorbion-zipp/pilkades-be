<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Candidate::orderBy('candidate_number');

        if ($electionId = $request->query('election_id')) {
            $query->where('election_id', $electionId);
        }

        $candidates = $query->get();

        return response()->json([
            'candidates' => $candidates->map(fn (Candidate $c) => $c->toApi(
                Vote::where('candidate_id', (string) $c->_id)->count()
            ))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $electionId = $request->input('election_id');
        $election = $electionId ? Election::find($electionId) : null;

        if ($electionId && ! $election) {
            return response()->json(['message' => 'Data pemilihan tidak ditemukan.'], 422);
        }

        $uniqueRule = Rule::unique('mongodb.candidates', 'candidate_number');
        if ($electionId) {
            $uniqueRule->where('election_id', (string) $electionId);
        }

        $viceRule = (! $election || $election->position_type === Election::POSITION_BUPATI)
            ? 'required|string|max:255'
            : 'nullable|string|max:255';

        $partyRule = ($election && $election->position_type === Election::POSITION_DPRD)
            ? 'required|string|max:255'
            : 'nullable|string|max:255';

        $data = $request->validate([
            'election_id' => 'nullable|string',
            'candidate_number' => ['required', 'string', 'max:10', $uniqueRule],
            'name' => 'required|string|max:255',
            'vice_name' => $viceRule,
            'party_name' => $partyRule,
            'vision_mission' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $candidate = Candidate::create([
            'election_id' => $election ? (string) $election->_id : null,
            'candidate_number' => $data['candidate_number'],
            'name' => $data['name'],
            'vice_name' => isset($data['vice_name']) && trim((string) $data['vice_name']) !== '' ? trim((string) $data['vice_name']) : null,
            'party_name' => isset($data['party_name']) && trim((string) $data['party_name']) !== '' ? trim((string) $data['party_name']) : null,
            'vision_mission' => $this->sanitizeRichText($data['vision_mission'] ?? null),
            'photo_url' => $this->storePhoto($request, $data['candidate_number']),
        ]);

        return response()->json([
            'message' => 'Paslon berhasil ditambahkan.',
            'candidate' => $candidate->toApi(),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $candidate = Candidate::find($id);

        if (! $candidate) {
            return response()->json(['message' => 'Data paslon tidak ditemukan.'], 404);
        }

        $electionId = $candidate->election_id ? (string) $candidate->election_id : null;
        $election = $electionId ? Election::find($electionId) : null;

        $uniqueRule = Rule::unique('mongodb.candidates', 'candidate_number')->ignore($id, '_id');
        if ($electionId) {
            $uniqueRule->where('election_id', $electionId);
        }

        $viceRule = (! $election || $election->position_type === Election::POSITION_BUPATI)
            ? 'required|string|max:255'
            : 'nullable|string|max:255';

        $partyRule = ($election && $election->position_type === Election::POSITION_DPRD)
            ? 'required|string|max:255'
            : 'nullable|string|max:255';

        $data = $request->validate([
            'candidate_number' => ['required', 'string', 'max:10', $uniqueRule],
            'name' => 'required|string|max:255',
            'vice_name' => $viceRule,
            'party_name' => $partyRule,
            'vision_mission' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $photoUrl = $candidate->photo_url;

        if ($request->hasFile('photo')) {
            $this->deletePhoto($candidate->photo_url);
            $photoUrl = $this->storePhoto($request, $data['candidate_number']);
        }

        $candidate->update([
            'candidate_number' => $data['candidate_number'],
            'name' => $data['name'],
            'vice_name' => isset($data['vice_name']) && trim((string) $data['vice_name']) !== '' ? trim((string) $data['vice_name']) : null,
            'party_name' => isset($data['party_name']) && trim((string) $data['party_name']) !== '' ? trim((string) $data['party_name']) : null,
            'vision_mission' => $this->sanitizeRichText($data['vision_mission'] ?? null),
            'photo_url' => $photoUrl,
        ]);

        return response()->json([
            'message' => 'Data paslon berhasil diperbarui.',
            'candidate' => $candidate->fresh()->toApi(),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $candidate = Candidate::find($id);

        if (! $candidate) {
            return response()->json(['message' => 'Data paslon tidak ditemukan.'], 404);
        }

        // MongoDB tidak punya foreign key, jadi suara paslon dihapus manual.
        Vote::where('candidate_id', (string) $candidate->_id)->delete();

        $this->deletePhoto($candidate->photo_url);
        $candidate->delete();

        return response()->json(['message' => 'Paslon berhasil dihapus.']);
    }

    private function storePhoto(Request $request, string $candidateNumber): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        $file = $request->file('photo');
        $fileName = 'paslon_'.time().'_'.substr(md5(uniqid('', true)), 0, 6).'_'.$candidateNumber.'.'.$file->getClientOriginalExtension();
        $file->move(public_path('images'), $fileName);

        return url('/images/'.$fileName);
    }

    private function deletePhoto(?string $photoUrl): void
    {
        if (! $photoUrl) {
            return;
        }

        $path = public_path('images/'.basename($photoUrl));

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Bersihkan HTML dari editor rich text: hanya tag format dasar yang
     * dipertahankan dan seluruh atribut dibuang.
     */
    private function sanitizeRichText(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#<(script|style)\b[^>]*>.*#is', '', (string) $html);

        $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><h3><h4><blockquote>';
        $clean = strip_tags((string) $html, $allowed);
        $clean = preg_replace('/<([a-z0-9]+)(?:\s[^>]*?)?(\/?)>/i', '<$1$2>', $clean);
        $clean = trim((string) $clean);

        if ($clean === '' || preg_replace('/<[^>]*>|&nbsp;|\s/', '', $clean) === '') {
            return null;
        }

        return $clean;
    }
}
