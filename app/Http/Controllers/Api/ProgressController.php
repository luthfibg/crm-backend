<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
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
     * Get the last follow-up time for the current user
     * Returns the most recent time_completed from progresses table
     */
    public function getLastFollowUp(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Get the most recent progress entry for this user
        $lastProgress = Progress::where('user_id', $user->id)
            ->whereNotNull('time_completed')
            ->orderBy('time_completed', 'desc')
            ->first();

        if (!$lastProgress) {
            return response()->json([
                'has_followup' => false,
                'last_followup_at' => null,
                'message' => 'Belum ada FU yang tercatat'
            ]);
        }

        return response()->json([
            'has_followup' => true,
            'last_followup_at' => $lastProgress->time_completed,
            'customer_id' => $lastProgress->customer_id,
            'daily_goal_id' => $lastProgress->daily_goal_id
        ]);
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

            // 7. ⭐ PERUBAHAN: Jangan auto-advance, tapi return indicator bahwa summary diperlukan
            if ($isKpiCompleted) {
                Log::info("✅ KPI Completed via Update - Waiting for Summary", [
                    'customer_id' => $customer->id,
                    'progress_id' => $progress->id,
                    'kpi_id' => $currentKpiId,
                ]);
            }

            DB::commit();

            $latestAttachment = ProgressAttachment::where('progress_id', $progress->id)->latest()->first();
            
            return response()->json([
                'status' => true, 
                'is_valid' => $isValid, 
                'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted,
                'summary_required' => $isKpiCompleted, // ⭐ Indicator bahwa user harus input summary
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult,
                'user_input' => $latestAttachment?->content,
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

            // 7. STRICT MODE: HANYA NAIK JIKA 100% APPROVED + SUMMARY SUBMITTED
            $isKpiCompleted = ($totalAssigned > 0 && $totalApproved >= $totalAssigned);

            // ⭐ PERUBAHAN: Jangan auto-advance, tapi return indicator bahwa summary diperlukan
            if ($isKpiCompleted) {
                Log::info("✅ KPI Completed - Waiting for Summary", [
                    'customer_id' => $customer->id,
                    'kpi_id' => $currentKpiId,
                    'progress' => $currentProgress . '%'
                ]);
            }

            DB::commit();
            $latestAttachment = ProgressAttachment::where('progress_id', $progress->id)
            ->latest()
            ->first();
            
            return response()->json([
                'status' => true, 
                'is_valid' => $isValid, 
                'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted,
                'summary_required' => $isKpiCompleted, // ⭐ Indicator bahwa user harus input summary
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult,
                'user_input' => $latestAttachment?->content 
                ?? $latestAttachment?->original_name,
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

            // 7. ⭐ PERUBAHAN: Jangan auto-advance, tapi return indicator bahwa summary diperlukan
            if ($isKpiCompleted) {
                Log::info("✅ KPI Completed via Resubmit - Waiting for Summary", [
                    'customer_id' => $customer->id,
                    'progress_id' => $existingProgress->id,
                    'kpi_id' => $currentKpiId,
                ]);
            }

            DB::commit();

            $latestAttachment = ProgressAttachment::where('progress_id', $existingProgress->id)
            ->latest()
            ->first();

            return response()->json([
                'status' => true,
                'is_valid' => $isValid,
                'message' => $reviewNote,
                'resubmitted' => true,
                'kpi_completed' => $isKpiCompleted,
                'summary_required' => $isKpiCompleted, // ⭐ Indicator bahwa user harus input summary
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult,
                'user_input' => $latestAttachment?->content 
                    ?? $latestAttachment?->original_name,
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

        // 1.5 VALIDASI DATE
        if ($inputType === 'date') {
            if (!$evidence) {
                return ['is_valid' => false, 'message' => 'Tanggal tidak boleh kosong'];
            }

            // Extract date patterns - flexible to allow additional text
            // Common formats: DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD, DD-MM-YY, etc.
            $datePattern = '/\b(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}|\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\b/';
            
            if (preg_match($datePattern, $evidence, $matches)) {
                $dateStr = $matches[0];
                
                // Try to parse the date
                $parsedDate = null;
                $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-y', 'd/m/y', 'Y/m/d'];
                
                foreach ($formats as $format) {
                    $date = \DateTime::createFromFormat($format, $dateStr);
                    if ($date && $date->format($format) === $dateStr) {
                        $parsedDate = $date;
                        break;
                    }
                }
                
                if ($parsedDate) {
                    return ['is_valid' => true, 'message' => 'Sistem: Tanggal valid (' . $parsedDate->format('d-m-Y') . ')'];
                }
            }

            return ['is_valid' => false, 'message' => 'Sistem: Format tanggal tidak valid. Contoh: 24-01-2026 atau 24/01/2026'];
        }

        // 1.6 VALIDASI NUMBER
        if ($inputType === 'number') {
            if (!$evidence) {
                return ['is_valid' => false, 'message' => 'Angka tidak boleh kosong'];
            }

            // Extract numbers - flexible to allow additional text/context
            // Matches integers or decimals (with dot or comma as separator)
            if (preg_match('/\b\d+([.,]\d+)?\b/', $evidence, $matches)) {
                $number = $matches[0];
                // Normalize comma to dot for decimal
                $normalizedNumber = str_replace(',', '.', $number);
                
                if (is_numeric($normalizedNumber)) {
                    return ['is_valid' => true, 'message' => 'Sistem: Angka valid (' . $number . ')'];
                }
            }

            return ['is_valid' => false, 'message' => 'Sistem: Tidak ditemukan angka yang valid'];
        }

        // 1.7 VALIDASI CURRENCY (RUPIAH)
        if ($inputType === 'currency') {
            if (!$evidence) {
                return ['is_valid' => false, 'message' => 'Nominal tidak boleh kosong'];
            }

            // Extract Rupiah amount - very flexible to allow various formats
            // Matches: Rp 10.000, Rp10000, 10000, 10.000, Rp 10.000.000, etc.
            $currencyPattern = '/(?:Rp\.?\s*)?(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)/i';
            
            if (preg_match($currencyPattern, $evidence, $matches)) {
                $amount = $matches[1];
                // Remove dots and commas for validation
                $cleanAmount = preg_replace('/[.,]/', '', $amount);
                
                if (is_numeric($cleanAmount) && $cleanAmount > 0) {
                    // Format nicely for display
                    $formattedAmount = 'Rp ' . number_format((int)$cleanAmount, 0, ',', '.');
                    return ['is_valid' => true, 'message' => 'Sistem: Nominal valid (' . $formattedAmount . ')'];
                }
            }

            return ['is_valid' => false, 'message' => 'Sistem: Format nominal tidak valid. Contoh: Rp 10.000 atau 10000'];
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

        // 3. VALIDASI TEXT - AI-powered understanding
        if ($inputType === 'text') {
            if (!$evidence || strlen(trim($evidence)) < 5) {
                return ['is_valid' => false, 'message' => 'Sistem: Jawaban terlalu pendek (min 5 karakter)'];
            }

            // Use OpenAI to analyze if the mission was completed
            try {
                $openaiApiKey = env('OPENAI_API_KEY');
                if (!$openaiApiKey) {
                    // Fallback to simple keyword check if no API key
                    return $this->fallbackTextValidation($evidence, $dailyGoal);
                }

                $prompt = "Analyze if the following user input indicates successful completion of the mission: '{$dailyGoal->description}'.\n\nUser input: '{$evidence}'\n\nRespond with only 'APPROVED' or 'REJECTED' followed by a brief reason (max 50 words). Consider the context and whether the user explains how the mission objectives were met.";

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $openaiApiKey,
                    'Content-Type' => 'application/json',
                ])->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 100,
                    'temperature' => 0.3,
                ]);

                if ($response->successful()) {
                    $result = $response->json();
                    $aiResponse = trim($result['choices'][0]['message']['content'] ?? '');

                    if (str_starts_with(strtoupper($aiResponse), 'APPROVED')) {
                        $reason = trim(substr($aiResponse, 8));
                        return ['is_valid' => true, 'message' => 'Sistem: ' . ($reason ?: 'Misi disetujui berdasarkan analisis AI')];
                    } elseif (str_starts_with(strtoupper($aiResponse), 'REJECTED')) {
                        $reason = trim(substr($aiResponse, 8));
                        return ['is_valid' => false, 'message' => 'Sistem: ' . ($reason ?: 'Misi ditolak berdasarkan analisis AI')];
                    }
                }

                // If API fails, fallback
                return $this->fallbackTextValidation($evidence, $dailyGoal);

            } catch (\Exception $e) {
                Log::error('OpenAI API error: ' . $e->getMessage());
                return $this->fallbackTextValidation($evidence, $dailyGoal);
            }
        }

        return ['is_valid' => true, 'message' => 'Sistem: Data diterima'];
    }

    /**
     * Fallback text validation using improved keyword matching
     */
    private function fallbackTextValidation($evidence, $dailyGoal)
    {
        $evidenceText = strtolower($evidence);
        $description = strtolower($dailyGoal->description);

        // Simple completion indicators
        $completionIndicators = [
            'sudah selesai', 'berhasil', 'selesai', 'disetujui', 'approved', 'ok', 'oke', 'deal', 'setuju',
            'lancar', 'sukses', 'tercapai', 'terpenuhi', 'memenuhi', 'bagus', 'baik', 'mantap', 'siap', 'done'
        ];

        $failureIndicators = [
            'belum', 'gagal', 'tidak', 'kurang', 'belum selesai', 'belum berhasil', 'belum tercapai'
        ];

        $completionCount = 0;
        $failureCount = 0;

        foreach ($completionIndicators as $indicator) {
            if (str_contains($evidenceText, $indicator)) {
                $completionCount++;
            }
        }

        foreach ($failureIndicators as $indicator) {
            if (str_contains($evidenceText, $indicator)) {
                $failureCount++;
            }
        }

        // Analyze by sentences - look for explanations of successful completion
        $sentences = preg_split('/[.!?\n]+/', $evidenceText);
        $positiveSentences = 0;
        $negativeSentences = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            $sentenceCompletion = 0;
            $sentenceFailure = 0;

            foreach ($completionIndicators as $indicator) {
                if (str_contains($sentence, $indicator)) {
                    $sentenceCompletion++;
                }
            }

            foreach ($failureIndicators as $indicator) {
                if (str_contains($sentence, $indicator)) {
                    $sentenceFailure++;
                }
            }

            if ($sentenceCompletion > $sentenceFailure) {
                $positiveSentences++;
            } elseif ($sentenceFailure > $sentenceCompletion) {
                $negativeSentences++;
            }
        }

        // Decision logic
        if ($completionCount > $failureCount && $positiveSentences >= $negativeSentences) {
            return ['is_valid' => true, 'message' => 'Sistem: Indikasi penyelesaian misi terdeteksi'];
        } elseif ($failureCount > $completionCount || $negativeSentences > $positiveSentences) {
            return ['is_valid' => false, 'message' => 'Sistem: Misi belum terpenuhi berdasarkan penjelasan'];
        }

        // If unclear, check minimum length
        if (strlen(trim($evidence)) >= 20) {
            return ['is_valid' => true, 'message' => 'Sistem: Jawaban diterima (perlu verifikasi manual)'];
        }

        return ['is_valid' => false, 'message' => 'Sistem: Jawaban kurang detail tentang penyelesaian misi'];
    }

    /**
     * AUTO-ADVANCE customer ke KPI berikutnya atau mark as completed
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
        } else {
            // No next KPI - this is the final completion (After Sales done)
            $customer->status = 'Completed';
            $customer->status_changed_at = now();
            $customer->save();

            Log::info("✅ Customer sale completed and moved to history", [
                'customer_id' => $customer->id,
                'final_kpi' => $currentKpi->code,
                'status' => 'Completed'
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
