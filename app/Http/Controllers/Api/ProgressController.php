<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Progress;
use App\Models\ProgressAttachment;
use App\Models\DailyGoal;
use App\Models\Customer;
use App\Models\KPI;
use App\Models\CustomerKpiScore;
use App\Services\ScoringService;

class ProgressController extends Controller
{
    protected $scoringService;

    public function __construct(ScoringService $scoringService)
    {
        $this->scoringService = $scoringService;
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        
        $validator = Validator::make($request->all(), [
            'daily_goal_id' => 'required|integer|exists:daily_goals,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'evidence' => 'nullable',
            'note' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $dailyGoal = DailyGoal::findOrFail($request->daily_goal_id);
        $customer = Customer::findOrFail($request->customer_id);

        // 1. CEK DUPLIKASI
        $exists = Progress::where('customer_id', $customer->id)
            ->where('daily_goal_id', $dailyGoal->id)
            ->exists();
        if ($exists) {
            return response()->json([
                'status' => false,
                'is_valid' => false,
                'message' => 'Misi ini sudah diselesaikan sebelumnya.'
            ], 409);
        }

        DB::beginTransaction();
        try {
            // 2. VALIDASI EVIDENCE
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            // 3. HITUNG BOBOT PROGRESS (untuk progress_value saja, bukan scoring)
            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            // 4. SIMPAN PROGRESS (selalu simpan, baik approved maupun rejected)
            $progress = Progress::create([
                'user_id' => $actor->id,
                'kpi_id' => $dailyGoal->kpi_id,
                'daily_goal_id' => $dailyGoal->id,
                'customer_id' => $customer->id,
                'time_completed' => now(), // Selalu set time
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

            // 5. HANDLE ATTACHMENT
            if ($request->hasFile('evidence')) {
                $file = $request->file('evidence');
                $path = $file->store('progress_attachments', 'public');
                ProgressAttachment::create([
                    'progress_id' => $progress->id,
                    'file_path' => $path,
                    'type' => $dailyGoal->input_type,
                    'original_name' => $file->getClientOriginalName()
                ]);
            } elseif ($request->evidence && !in_array($dailyGoal->input_type, ['file', 'image', 'video'])) {
                ProgressAttachment::create([
                    'progress_id' => $progress->id,
                    'content' => $request->evidence,
                    'type' => $dailyGoal->input_type
                ]);
            }

            // 6. ⭐ UPDATE SCORING (HANYA jika valid/approved)
            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore(
                    $customer->id,
                    $dailyGoal->kpi_id,
                    $actor->id
                );
            }

            // 7. CEK APAKAH KPI CURRENT SUDAH 100% (berdasarkan approved tasks)
            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            
            $totalAssigned = DailyGoal::where('user_id', $actor->id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();

            $totalCompleted = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id)
                ->where('status', 'approved')
                ->whereNotNull('time_completed')
                ->distinct('daily_goal_id')
                ->count('daily_goal_id');

            $currentProgress = $totalAssigned > 0 ? round(($totalCompleted / $totalAssigned) * 100, 2) : 0;

            // 8. JIKA SUDAH 100% APPROVED, NAIK KE KPI BERIKUTNYA
            $isFinished = ($totalAssigned > 0 && $totalCompleted >= $totalAssigned);

            if ($isFinished) {
                $this->advanceCustomerToNextKPI($customer, $currentKpiId);
            }

            DB::commit();
            
            return response()->json([
                'status' => true, 
                'is_valid' => $isValid, 
                'message' => $reviewNote,
                'kpi_completed' => $isFinished,
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult, // Null jika rejected
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Progress store error: " . $e->getMessage(), [
                'daily_goal_id' => $request->daily_goal_id,
                'customer_id' => $request->customer_id,
            ]);
            return response()->json([
                'status' => false,
                'is_valid' => false,
                'error' => 'Terjadi kesalahan saat menyimpan progress. Silakan coba lagi.'
            ], 500);
        }
    }

    /**
     * Sistem validasi cerdas sederhana
     */
    private function validateEvidence($dailyGoal, $request)
    {
        $inputType = $dailyGoal->input_type;
        $evidence = $request->evidence;
        $file = $request->file('evidence');

        // 1. VALIDASI PHONE
        if ($inputType === 'phone') {
            if (!$evidence) {
                return ['is_valid' => false, 'message' => 'Nomor telepon tidak boleh kosong'];
            }

            $cleanPhone = preg_replace('/[^0-9+]/', '', $evidence);
            
            // Format Indonesia: +62, 62, atau 0 diikuti 9-13 digit
            if (preg_match('/^(\\+62|62|0)8[1-9][0-9]{6,9}$/', $cleanPhone)) {
                return ['is_valid' => true, 'message' => 'Sistem: Nomor telepon valid'];
            }

            return ['is_valid' => false, 'message' => 'Sistem: Format nomor tidak valid. Contoh: 08123456789'];
        }

        // 2. VALIDASI FILE
        if (in_array($inputType, ['file', 'image', 'video'])) {
            if (!$file) {
                return ['is_valid' => false, 'message' => 'File belum diupload'];
            }

            // Validasi tipe file
            if ($inputType === 'image') {
                $validMimes = ['image/jpeg', 'image/jpg', 'image/png'];
                if (in_array($file->getMimeType(), $validMimes)) {
                    return ['is_valid' => true, 'message' => 'Sistem: Gambar valid'];
                }
                return ['is_valid' => false, 'message' => 'Sistem: File harus berupa gambar (JPG/PNG)'];
            }

            if ($inputType === 'video') {
                $validMimes = ['video/mp4', 'video/avi', 'video/quicktime'];
                if (in_array($file->getMimeType(), $validMimes)) {
                    return ['is_valid' => true, 'message' => 'Sistem: Video valid'];
                }
                return ['is_valid' => false, 'message' => 'Sistem: File harus berupa video'];
            }

            // File biasa
            return ['is_valid' => true, 'message' => 'Sistem: File berhasil diterima'];
        }

        // 3. VALIDASI TEXT - Keyword Matching
        if ($inputType === 'text') {
            if (!$evidence || strlen(trim($evidence)) < 5) {
                return ['is_valid' => false, 'message' => 'Sistem: Jawaban terlalu pendek (min 5 karakter)'];
            }

            $description = strtolower($dailyGoal->description);
            $evidenceText = strtolower($evidence);

            // Dictionary keyword
            $keywords = [
                'company profile' => ['profil', 'perusahaan', 'company', 'profile', 'cv', 'pt', 'perkenalan'],
                'kontak' => ['kontak', 'nomor', 'telepon', 'whatsapp', 'email', 'hp'],
                'ketua' => ['ketua', 'kepala', 'chairman', 'direktur', 'pimpinan'],
                'anggota' => ['anggota', 'member', 'peserta', 'jumlah', 'total'],
                'jadwal' => ['jadwal', 'tanggal', 'waktu', 'schedule', 'agenda'],
                'roadshow' => ['roadshow', 'presentasi', 'sosialisasi', 'demo'],
                'support' => ['support', 'dukungan', 'bantuan', 'assistance'],
                'dealing' => ['dealing', 'negosiasi', 'kesepakatan', 'agreement'],
                'hot prospect' => ['hot', 'prospek', 'potensial', 'berminat'],
                'poc' => ['poc', 'proof of concept', 'uji coba', 'trial'],
                'training' => ['training', 'pelatihan', 'workshop', 'bimbingan'],
                'guru' => ['guru', 'teacher', 'pengajar', 'dosen'],
                'penawaran' => ['penawaran', 'proposal', 'quotation', 'surat'],
                'harga' => ['harga', 'price', 'biaya', 'cost', 'tarif'],
                'tawar' => ['tawar', 'nego', 'diskon', 'potongan'],
                'unit' => ['unit', 'jumlah', 'quantity', 'item'],
                'pengadaan' => ['pengadaan', 'procurement', 'pembelian', 'purchase'],
                'pengiriman' => ['pengiriman', 'delivery', 'kirim', 'distribusi'],
                'pembayaran' => ['bayar', 'payment', 'invoice', 'pelunasan']
            ];

            // Cari keyword yang relevan
            $relevantKeywords = [];
            foreach ($keywords as $key => $wordList) {
                if (str_contains($description, $key)) {
                    $relevantKeywords = array_merge($relevantKeywords, $wordList);
                }
            }

            // Jika tidak ada keyword spesifik, validasi umum
            if (empty($relevantKeywords)) {
                if (strlen(trim($evidence)) >= 10) {
                    return ['is_valid' => true, 'message' => 'Sistem: Jawaban diterima'];
                }
                return ['is_valid' => false, 'message' => 'Sistem: Jawaban kurang detail (min 10 karakter)'];
            }

            // Cek keyword match
            $matchedKeywords = [];
            foreach ($relevantKeywords as $keyword) {
                if (str_contains($evidenceText, $keyword)) {
                    $matchedKeywords[] = $keyword;
                }
            }

            if (!empty($matchedKeywords)) {
                return [
                    'is_valid' => true,
                    'message' => 'Sistem: Relevan - ' . implode(', ', array_slice($matchedKeywords, 0, 2))
                ];
            }

            return [
                'is_valid' => false,
                'message' => 'Sistem: Jawaban tidak relevan. Harap sertakan: ' . implode(', ', array_slice($relevantKeywords, 0, 3))
            ];
        }

        return ['is_valid' => true, 'message' => 'Sistem: Data diterima'];
    }

    /**
     * Advance customer to next KPI
     */
    private function advanceCustomerToNextKPI($customer, $currentKpiId)
    {
        $currentKpi = KPI::find($currentKpiId);
        
        $nextKpi = KPI::where('sequence', '>', $currentKpi->sequence)
            ->orderBy('sequence', 'asc')
            ->first();

        if ($nextKpi) {
            $statusMap = [
                'visit1' => 'New',
                'visit2' => 'Warm Prospect',
                'visit3' => 'Hot Prospect',
                'deal' => 'Deal Won',
                'after_sales' => 'After Sales'
            ];
            
            $customer->current_kpi_id = $nextKpi->id;
            $customer->kpi_id = $nextKpi->id;
            $customer->status = $statusMap[$nextKpi->code] ?? $customer->status;
            $customer->status_changed_at = now();
            $customer->save();

            \Log::info("Customer advanced to next KPI", [
                'customer_id' => $customer->id,
                'new_kpi' => $nextKpi->code,
                'new_status' => $customer->status
            ]);
        }
    }

    

    public function resetProspect(Request $request, $id)
    {
        $admin = $request->user();
        
        // Keamanan berlapis: Cek Role DAN Mode Developer
        if ($admin->role !== 'administrator' || !$admin->is_developer_mode) {
            return response()->json(['message' => 'Unauthorized or Dev Mode is OFF'], 403);
        }

        $customer = Customer::findOrFail($id);

        DB::beginTransaction();
        try {
            // 1. Ambil semua progress_id milik customer ini untuk hapus attachment
            $progressIds = Progress::where('customer_id', $customer->id)->pluck('id');
            
            // 2. Hapus file fisik jika ada (opsional, tergantung kebijakan storage)
            $attachments = ProgressAttachment::whereIn('progress_id', $progressIds)->get();
            foreach ($attachments as $file) {
                if ($file->file_path) Storage::disk('public')->delete($file->file_path);
            }

            // 3. Hapus data Progress & Attachment (Relasi cascading jika di set di DB, 
            // jika tidak, hapus manual)
            ProgressAttachment::whereIn('progress_id', $progressIds)->delete();
            Progress::where('customer_id', $customer->id)->delete();

            // 4. Hapus Scoring History
            CustomerKpiScore::where('customer_id', $customer->id)->delete();

            // 5. Reset Customer ke State Netral (Bukan prospek lagi)
            $customer->update([
                'kpi_id' => null,
                'current_kpi_id' => null,
                'status' => 'New',
                'earned_points' => 0,
                'max_points' => 0,
                'score_percentage' => 0,
                'status_changed_at' => null
            ]);

            DB::commit();
            return response()->json(['message' => 'Prospect data cleared. Customer remains.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
