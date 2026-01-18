<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
     * ⭐ STORE: Submit progress PERTAMA KALI
     * 
     * Flow:
     * 1. Cek apakah task ini pernah di-submit
     * 2. Jika sudah pernah (approved/rejected), return error dengan progress_id untuk update
     * 3. Jika belum, buat progress baru
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

        // 1. ⭐ CEK APAKAH SUDAH PERNAH SUBMIT
        $existingProgress = Progress::where('customer_id', $customer->id)
            ->where('daily_goal_id', $dailyGoal->id)
            ->first();

        if ($existingProgress) {
            // Jika sudah approved, tidak boleh diubah
            if ($existingProgress->status === 'approved') {
                return response()->json([
                    'status' => false,
                    'is_valid' => false,
                    'message' => 'Misi ini sudah diselesaikan dan diapprove. Tidak dapat diubah.'
                ], 409);
            }

            // Jika rejected, otomatis lakukan update (resubmit)
            // Ini memungkinkan user untuk resubmit langsung tanpa harus memanggil endpoint update
            return $this->handleResubmit($request, $existingProgress, $dailyGoal, $customer, $actor);
        }

        // 2. Lanjut submit baru
        return $this->saveProgress($request, $dailyGoal, $customer, $actor);
    }

    /**
     * ⭐ UPDATE: Re-submit progress yang REJECTED
     * 
     * Hanya bisa update progress dengan status 'rejected'
     */
    public function update(Request $request, $progressId)
    {
        $actor = $request->user();
        
        $validator = Validator::make($request->all(), [
            'evidence' => 'nullable',
            'note' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $progress = Progress::findOrFail($progressId);

        // ⭐ HANYA BOLEH UPDATE YANG REJECTED
        if ($progress->status === 'approved') {
            return response()->json([
                'status' => false,
                'is_valid' => false,
                'message' => 'Progress yang sudah approved tidak dapat diubah.'
            ], 403);
        }

        $dailyGoal = DailyGoal::findOrFail($progress->daily_goal_id);
        $customer = Customer::findOrFail($progress->customer_id);

        DB::beginTransaction();
        try {
            // 1. VALIDASI EVIDENCE BARU
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            // 2. HITUNG BOBOT (untuk approved)
            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            // 3. UPDATE PROGRESS
            $progress->update([
                'time_completed' => now(),
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

            // 4. UPDATE/CREATE ATTACHMENT
            // Hapus attachment lama
            ProgressAttachment::where('progress_id', $progress->id)->delete();

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

            // 5. UPDATE SCORING (HANYA jika sekarang approved)
            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore(
                    $customer->id,
                    $dailyGoal->kpi_id,
                    $actor->id
                );
            }

            // 6. CEK APAKAH KPI SUDAH 100%
            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            
            // Mapping category ke daily_goal_type_id
            $categoryToTypeMapping = [
                'Pendidikan' => 1,
                'Pemerintahan' => 2,
                'Web Inquiry Corporate' => 3,
                'Web Inquiry CNI' => 4,
                'Web Inquiry C&I' => 4,
            ];
            
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
            
            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%');
                
            // Filter berdasarkan category
            if (strtolower($customer->category) === 'pemerintahan') {
                $groupMapping = [
                    'UKPBJ' => 'KEDINASAN',
                    'RUMAH SAKIT' => 'KEDINASAN',
                    'KANTOR KEDINASAN' => 'KEDINASAN',
                    'KANTOR BALAI' => 'KEDINASAN',
                    'KELURAHAN' => 'KECAMATAN',
                    'KECAMATAN' => 'KECAMATAN',
                    'PUSKESMAS' => 'PUSKESMAS'
                ];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }
            
            $totalAssigned = $totalAssignedQuery->count();

            $totalApproved = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id)
                ->where('status', 'approved')
                ->whereNotNull('time_completed')
                ->distinct('daily_goal_id')
                ->count('daily_goal_id');

            $currentProgress = $totalAssigned > 0 ? round(($totalApproved / $totalAssigned) * 100, 2) : 0;
            $isKpiCompleted = ($totalAssigned > 0 && $totalApproved >= $totalAssigned);

            // 7. AUTO-ADVANCE jika 100%
            if ($isKpiCompleted) {
                $this->advanceCustomerToNextKPI($customer, $currentKpiId);
                
                Log::info("✅ KPI Completed via Update & Auto-Advanced", [
                    'customer_id' => $customer->id,
                    'progress_id' => $progress->id,
                    'kpi_id' => $currentKpiId,
                ]);
            }

            DB::commit();
            
            return response()->json([
                'status' => true, 
                'is_valid' => $isValid, 
                'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted,
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress update error: " . $e->getMessage(), [
                'progress_id' => $progressId,
            ]);
            return response()->json([
                'status' => false,
                'is_valid' => false,
                'error' => 'Terjadi kesalahan saat update progress. Silakan coba lagi.'
            ], 500);
        }
    }

    /**
     * SHARED METHOD: Save progress (digunakan oleh store dan internal)
     */
    private function saveProgress($request, $dailyGoal, $customer, $actor)
    {
        DB::beginTransaction();
        try {
            // 1. VALIDASI EVIDENCE
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            // 2. HITUNG BOBOT PROGRESS
            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            // 3. SIMPAN PROGRESS
            $progress = Progress::create([
                'user_id' => $actor->id,
                'kpi_id' => $dailyGoal->kpi_id,
                'daily_goal_id' => $dailyGoal->id,
                'customer_id' => $customer->id,
                'time_completed' => now(),
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

            // 4. HANDLE ATTACHMENT
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

            // 5. UPDATE SCORING (HANYA jika approved)
            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore(
                    $customer->id,
                    $dailyGoal->kpi_id,
                    $actor->id
                );
            }

            // 6. CEK APAKAH KPI CURRENT SUDAH 100% APPROVED
            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            
            // Mapping category ke daily_goal_type_id
            $categoryToTypeMapping = [
                'Pendidikan' => 1,
                'Pemerintahan' => 2,
                'Web Inquiry Corporate' => 3,
                'Web Inquiry CNI' => 4,
                'Web Inquiry C&I' => 4,
            ];
            
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
            
            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%');
                
            // Filter berdasarkan category
            if (strtolower($customer->category) === 'pemerintahan') {
                $groupMapping = [
                    'UKPBJ' => 'KEDINASAN',
                    'RUMAH SAKIT' => 'KEDINASAN',
                    'KANTOR KEDINASAN' => 'KEDINASAN',
                    'KANTOR BALAI' => 'KEDINASAN',
                    'KELURAHAN' => 'KECAMATAN',
                    'KECAMATAN' => 'KECAMATAN',
                    'PUSKESMAS' => 'PUSKESMAS'
                ];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }
            
            $totalAssigned = $totalAssignedQuery->count();

            $totalApproved = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id)
                ->where('status', 'approved')
                ->whereNotNull('time_completed')
                ->distinct('daily_goal_id')
                ->count('daily_goal_id');

            $currentProgress = $totalAssigned > 0 ? round(($totalApproved / $totalAssigned) * 100, 2) : 0;

            // 7. STRICT MODE: HANYA NAIK JIKA 100% APPROVED
            $isKpiCompleted = ($totalAssigned > 0 && $totalApproved >= $totalAssigned);

            if ($isKpiCompleted) {
                $this->advanceCustomerToNextKPI($customer, $currentKpiId);
                
                Log::info("✅ KPI Completed & Auto-Advanced", [
                    'customer_id' => $customer->id,
                    'kpi_id' => $currentKpiId,
                    'progress' => $currentProgress . '%'
                ]);
            }

            DB::commit();
            
            return response()->json([
                'status' => true, 
                'is_valid' => $isValid, 
                'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted,
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress store error: " . $e->getMessage(), [
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
     * ⭐ HANDLE RESUBMIT: Otomatis update progress yang rejected saat user submit ulang
     *
     * Ini memungkinkan user untuk resubmit melalui endpoint store tanpa harus memanggil update
     */
    private function handleResubmit($request, $existingProgress, $dailyGoal, $customer, $actor)
    {
        DB::beginTransaction();
        try {
            // 1. VALIDASI EVIDENCE BARU
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            // 2. HITUNG BOBOT (untuk approved)
            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            // 3. UPDATE PROGRESS
            $existingProgress->update([
                'time_completed' => now(),
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

            // 4. UPDATE/CREATE ATTACHMENT
            // Hapus attachment lama
            ProgressAttachment::where('progress_id', $existingProgress->id)->delete();

            if ($request->hasFile('evidence')) {
                $file = $request->file('evidence');
                $path = $file->store('progress_attachments', 'public');
                ProgressAttachment::create([
                    'progress_id' => $existingProgress->id,
                    'file_path' => $path,
                    'type' => $dailyGoal->input_type,
                    'original_name' => $file->getClientOriginalName()
                ]);
            } elseif ($request->evidence && !in_array($dailyGoal->input_type, ['file', 'image', 'video'])) {
                ProgressAttachment::create([
                    'progress_id' => $existingProgress->id,
                    'content' => $request->evidence,
                    'type' => $dailyGoal->input_type
                ]);
            }

            // 5. UPDATE SCORING (HANYA jika sekarang approved)
            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore(
                    $customer->id,
                    $dailyGoal->kpi_id,
                    $actor->id
                );
            }

            // 6. CEK APAKAH KPI SUDAH 100%
            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            
            // Mapping category ke daily_goal_type_id
            $categoryToTypeMapping = [
                'Pendidikan' => 1,
                'Pemerintahan' => 2,
                'Web Inquiry Corporate' => 3,
                'Web Inquiry CNI' => 4,
                'Web Inquiry C&I' => 4,
            ];
            
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
            
            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%');
                
            // Filter berdasarkan category
            if (strtolower($customer->category) === 'pemerintahan') {
                $groupMapping = [
                    'UKPBJ' => 'KEDINASAN',
                    'RUMAH SAKIT' => 'KEDINASAN',
                    'KANTOR KEDINASAN' => 'KEDINASAN',
                    'KANTOR BALAI' => 'KEDINASAN',
                    'KELURAHAN' => 'KECAMATAN',
                    'KECAMATAN' => 'KECAMATAN',
                    'PUSKESMAS' => 'PUSKESMAS'
                ];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }
            
            $totalAssigned = $totalAssignedQuery->count();

            $totalApproved = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id)
                ->where('status', 'approved')
                ->whereNotNull('time_completed')
                ->distinct('daily_goal_id')
                ->count('daily_goal_id');

            $currentProgress = $totalAssigned > 0 ? round(($totalApproved / $totalAssigned) * 100, 2) : 0;
            $isKpiCompleted = ($totalAssigned > 0 && $totalApproved >= $totalAssigned);

            // 7. AUTO-ADVANCE jika 100%
            if ($isKpiCompleted) {
                $this->advanceCustomerToNextKPI($customer, $currentKpiId);
                
                Log::info("✅ KPI Completed via Resubmit & Auto-Advanced", [
                    'customer_id' => $customer->id,
                    'progress_id' => $existingProgress->id,
                    'kpi_id' => $currentKpiId,
                ]);
            }

            DB::commit();
            
            return response()->json([
                'status' => true,
                'is_valid' => $isValid,
                'message' => $reviewNote,
                'resubmitted' => true,
                'kpi_completed' => $isKpiCompleted,
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress resubmit error: " . $e->getMessage(), [
                'progress_id' => $existingProgress->id,
                'daily_goal_id' => $dailyGoal->id,
                'customer_id' => $customer->id,
            ]);
            return response()->json([
                'status' => false,
                'is_valid' => false,
                'error' => 'Terjadi kesalahan saat resubmit progress. Silakan coba lagi.'
            ], 500);
        }
    }

    /**
     * Sistem validasi evidence
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

            return ['is_valid' => true, 'message' => 'Sistem: File berhasil diterima'];
        }

        // 3. VALIDASI TEXT - Keyword Matching
        if ($inputType === 'text') {
            if (!$evidence || strlen(trim($evidence)) < 5) {
                return ['is_valid' => false, 'message' => 'Sistem: Jawaban terlalu pendek (min 5 karakter)'];
            }

            $description = strtolower($dailyGoal->description);
            $evidenceText = strtolower($evidence);

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

            $relevantKeywords = [];
            foreach ($keywords as $key => $wordList) {
                if (str_contains($description, $key)) {
                    $relevantKeywords = array_merge($relevantKeywords, $wordList);
                }
            }

            if (empty($relevantKeywords)) {
                if (strlen(trim($evidence)) >= 10) {
                    return ['is_valid' => true, 'message' => 'Sistem: Jawaban diterima'];
                }
                return ['is_valid' => false, 'message' => 'Sistem: Jawaban kurang detail (min 10 karakter)'];
            }

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
     * AUTO-ADVANCE customer ke KPI berikutnya
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

            Log::info("✅ Customer advanced to next KPI", [
                'customer_id' => $customer->id,
                'old_kpi' => $currentKpi->code,
                'new_kpi' => $nextKpi->code,
                'new_status' => $customer->status
            ]);
        }
    }

    /**
     * Get progress history untuk customer
     */
    public function getCustomerProgress(Request $request, $customerId)
    {
        $actor = $request->user();
        $customer = Customer::findOrFail($customerId);

        $progresses = Progress::where('customer_id', $customerId)
            ->where('user_id', $actor->id)
            ->with(['dailyGoal', 'kpi', 'attachments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $progresses
        ]);
    }
}
