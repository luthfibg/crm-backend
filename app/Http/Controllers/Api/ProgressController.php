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

    public function index() { }

    public function getLastFollowUp(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $lastProgress = Progress::where('user_id', $user->id)
            ->whereNotNull('time_completed')
            ->orderBy('time_completed', 'desc')
            ->first();

        if (!$lastProgress) {
            return response()->json(['has_followup' => false, 'last_followup_at' => null, 'message' => 'Belum ada FU yang tercatat']);
        }

        return response()->json([
            'has_followup' => true,
            'last_followup_at' => $lastProgress->time_completed,
            'customer_id' => $lastProgress->customer_id,
            'daily_goal_id' => $lastProgress->daily_goal_id
        ]);
    }

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

        $existingProgress = Progress::where('customer_id', $customer->id)
            ->where('daily_goal_id', $dailyGoal->id)
            ->first();

        if ($existingProgress) {
            if ($existingProgress->status === 'approved') {
                return response()->json(['status' => false, 'is_valid' => false, 'message' => 'Misi ini sudah diselesaikan dan diapprove. Tidak dapat diubah.'], 409);
            }
            return $this->handleResubmit($request, $existingProgress, $dailyGoal, $customer, $actor);
        }

        return $this->saveProgress($request, $dailyGoal, $customer, $actor);
    }

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

        if ($progress->status === 'approved') {
            return response()->json(['status' => false, 'is_valid' => false, 'message' => 'Progress yang sudah approved tidak dapat diubah.'], 403);
        }

        $dailyGoal = DailyGoal::findOrFail($progress->daily_goal_id);
        $customer = Customer::findOrFail($progress->customer_id);

        DB::beginTransaction();
        try {
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            $progress->update([
                'time_completed' => now(),
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

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

            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore($customer->id, $dailyGoal->kpi_id, $actor->id);
            }

            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            
            $categoryToTypeMapping = ['Pendidikan' => 1, 'Pemerintah' => 2, 'Web Inquiry Corporate' => 3, 'Web Inquiry CNI' => 4, 'Web Inquiry C&I' => 4];
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
            
            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)->where('kpi_id', $currentKpiId)->where('description', 'NOT LIKE', 'Auto-generated%');
                
            if (strtolower($customer->category) === 'pemerintah') {
                $groupMapping = ['UKPBJ' => 'KEDINASAN', 'RUMAH SAKIT' => 'KEDINASAN', 'KANTOR KEDINASAN' => 'KEDINASAN', 'KANTOR BALAI' => 'KEDINASAN', 'KELURAHAN' => 'KECAMATAN', 'KECAMATAN' => 'KECAMATAN', 'PUSKESMAS' => 'PUSKESMAS'];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }
            
            $totalAssigned = $totalAssignedQuery->count();

            $totalProgressValue = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id)
                ->where('status', 'approved')
                ->whereNotNull('time_completed')
                ->sum('progress_value');

            $currentProgress = $totalAssigned > 0 ? round(($totalProgressValue / 100) * 100, 2) : 0;
            $isKpiCompleted = ($totalAssigned > 0 && $totalProgressValue >= 100);

            // If KPI is completed, set progress_value to 0 to keep progress at 80% until summary
            if ($isKpiCompleted && $isValid) {
                $progress->update(['progress_value' => 0]);
            }

            if ($isKpiCompleted) {
                Log::info("KPI Completed via Update - Waiting for Summary", ['customer_id' => $customer->id, 'progress_id' => $progress->id, 'kpi_id' => $currentKpiId]);
            }

            DB::commit();
            $latestAttachment = ProgressAttachment::where('progress_id', $progress->id)->latest()->first();
            
            return response()->json([
                'status' => true, 'is_valid' => $isValid, 'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted, 'summary_required' => $isKpiCompleted,
                'progress_percent' => $currentProgress, 'scoring' => $scoringResult,
                'user_input' => $latestAttachment?->content,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress update error: " . $e->getMessage(), ['progress_id' => $progressId]);
            return response()->json(['status' => false, 'is_valid' => false, 'error' => 'Terjadi kesalahan saat update progress.'], 500);
        }
    }

    private function saveProgress($request, $dailyGoal, $customer, $actor)
    {
        DB::beginTransaction();
        try {
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            $progress = Progress::create([
                'user_id' => $actor->id, 'kpi_id' => $dailyGoal->kpi_id,
                'daily_goal_id' => $dailyGoal->id, 'customer_id' => $customer->id,
                'time_completed' => now(), 'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

            if ($request->hasFile('evidence')) {
                $file = $request->file('evidence');
                $path = $file->store('progress_attachments', 'public');
                ProgressAttachment::create(['progress_id' => $progress->id, 'file_path' => $path, 'type' => $dailyGoal->input_type, 'original_name' => $file->getClientOriginalName()]);
            } elseif ($request->evidence && !in_array($dailyGoal->input_type, ['file', 'image', 'video'])) {
                ProgressAttachment::create(['progress_id' => $progress->id, 'content' => $request->evidence, 'type' => $dailyGoal->input_type]);
            }

            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore($customer->id, $dailyGoal->kpi_id, $actor->id);
            }

            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            $categoryToTypeMapping = ['Pendidikan' => 1, 'Pemerintah' => 2, 'Web Inquiry Corporate' => 3, 'Web Inquiry CNI' => 4, 'Web Inquiry C&I' => 4];
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
            
            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)->where('kpi_id', $currentKpiId)->where('description', 'NOT LIKE', 'Auto-generated%');
                
            if (strtolower($customer->category) === 'pemerintah') {
                $groupMapping = ['UKPBJ' => 'KEDINASAN', 'RUMAH SAKIT' => 'KEDINASAN', 'KANTOR KEDINASAN' => 'KEDINASAN', 'KANTOR BALAI' => 'KEDINASAN', 'KELURAHAN' => 'KECAMATAN', 'KECAMATAN' => 'KECAMATAN', 'PUSKESMAS' => 'PUSKESMAS'];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }
            
            $totalAssigned = $totalAssignedQuery->count();
            $totalApproved = Progress::where('customer_id', $customer->id)->where('kpi_id', $currentKpiId)->where('user_id', $actor->id)->where('status', 'approved')->whereNotNull('time_completed')->distinct('daily_goal_id')->count('daily_goal_id');
            $currentProgress = $totalAssigned > 0 ? round(($totalApproved / $totalAssigned) * 100, 2) : 0;
            $isKpiCompleted = ($totalAssigned > 0 && $totalApproved >= $totalAssigned);

            if ($isKpiCompleted) {
                Log::info("KPI Completed - Waiting for Summary", ['customer_id' => $customer->id, 'kpi_id' => $currentKpiId, 'progress' => $currentProgress . '%']);
            }

            DB::commit();
            $latestAttachment = ProgressAttachment::where('progress_id', $progress->id)->latest()->first();

            return response()->json([
                'status' => true, 'is_valid' => $isValid, 'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted, 'summary_required' => $isKpiCompleted,
                'progress_percent' => $currentProgress, 'scoring' => $scoringResult,
                'user_input' => $latestAttachment?->content ?? $latestAttachment?->original_name,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress store error: " . $e->getMessage(), ['daily_goal_id' => $request->daily_goal_id, 'customer_id' => $request->customer_id]);
            return response()->json(['status' => false, 'is_valid' => false, 'error' => 'Terjadi kesalahan saat menyimpan progress.'], 500);
        }
    }

    private function handleResubmit($request, $existingProgress, $dailyGoal, $customer, $actor)
    {
        DB::beginTransaction();
        try {
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            $existingProgress->update([
                'time_completed' => now(), 'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

            ProgressAttachment::where('progress_id', $existingProgress->id)->delete();

            if ($request->hasFile('evidence')) {
                $file = $request->file('evidence');
                $path = $file->store('progress_attachments', 'public');
                ProgressAttachment::create(['progress_id' => $existingProgress->id, 'file_path' => $path, 'type' => $dailyGoal->input_type, 'original_name' => $file->getClientOriginalName()]);
            } elseif ($request->evidence && !in_array($dailyGoal->input_type, ['file', 'image', 'video'])) {
                ProgressAttachment::create(['progress_id' => $existingProgress->id, 'content' => $request->evidence, 'type' => $dailyGoal->input_type]);
            }

            $scoringResult = null;
            if ($isValid) {
                $scoringResult = $this->scoringService->calculateKpiScore($customer->id, $dailyGoal->kpi_id, $actor->id);
            }

            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
            $categoryToTypeMapping = ['Pendidikan' => 1, 'Pemerintah' => 2, 'Web Inquiry Corporate' => 3, 'Web Inquiry CNI' => 4, 'Web Inquiry C&I' => 4];
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
            
            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)->where('kpi_id', $currentKpiId)->where('description', 'NOT LIKE', 'Auto-generated%');
                
            if (strtolower($customer->category) === 'pemerintah') {
                $groupMapping = ['UKPBJ' => 'KEDINASAN', 'RUMAH SAKIT' => 'KEDINASAN', 'KANTOR KEDINASAN' => 'KEDINASAN', 'KANTOR BALAI' => 'KEDINASAN', 'KELURAHAN' => 'KECAMATAN', 'KECAMATAN' => 'KECAMATAN', 'PUSKESMAS' => 'PUSKESMAS'];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }
            
            $totalAssigned = $totalAssignedQuery->count();
            $totalApproved = Progress::where('customer_id', $customer->id)->where('kpi_id', $currentKpiId)->where('user_id', $actor->id)->where('status', 'approved')->whereNotNull('time_completed')->distinct('daily_goal_id')->count('daily_goal_id');
            $currentProgress = $totalAssigned > 0 ? round(($totalApproved / $totalAssigned) * 100, 2) : 0;
            $isKpiCompleted = ($totalAssigned > 0 && $totalApproved >= $totalAssigned);

            if ($isKpiCompleted) {
                Log::info("KPI Completed via Resubmit - Waiting for Summary", ['customer_id' => $customer->id, 'progress_id' => $existingProgress->id, 'kpi_id' => $currentKpiId]);
            }

            DB::commit();
            $latestAttachment = ProgressAttachment::where('progress_id', $existingProgress->id)->latest()->first();

            return response()->json([
                'status' => true, 'is_valid' => $isValid, 'message' => $reviewNote, 'resubmitted' => true,
                'kpi_completed' => $isKpiCompleted, 'summary_required' => $isKpiCompleted,
                'progress_percent' => $currentProgress, 'scoring' => $scoringResult,
                'user_input' => $latestAttachment?->content ?? $latestAttachment?->original_name,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress resubmit error: " . $e->getMessage(), ['progress_id' => $existingProgress->id]);
            return response()->json(['status' => false, 'is_valid' => false, 'error' => 'Terjadi kesalahan saat resubmit progress.'], 500);
        }
    }

    private function validateEvidence($dailyGoal, $request)
    {
        $inputType = $dailyGoal->input_type;
        $evidence = data_get($request->all(), 'evidence');
        $file = $request->file('evidence');

        if ($inputType === 'phone') {
            if ($evidence === null || $evidence === '') return ['is_valid' => false, 'message' => 'Nomor telepon tidak boleh kosong'];
            $cleanPhone = preg_replace('/[^0-9+]/', '', $evidence);
            if (preg_match('/^(\+62|62|0)8[1-9][0-9]{6,9}$/', $cleanPhone)) {
                return ['is_valid' => true, 'message' => 'Sistem: Nomor telepon valid'];
            }
            return ['is_valid' => false, 'message' => 'Sistem: Format nomor tidak valid. Contoh: 08123456789'];
        }

        if ($inputType === 'date') {
            if ($evidence === null || $evidence === '') return ['is_valid' => false, 'message' => 'Tanggal tidak boleh kosong'];

            $evidenceLower = strtolower($evidence);

            // FRASE: "Belum ada target pengiriman" = REJECTED
            if (preg_match('/belum\s*(ada)?\s*target\s*pengiriman/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Target pengiriman belum ada'];
            }

            // FRASE: "Sudah ada target pengiriman" = APPROVED
            if (preg_match('/sudah\s*(ada)?\s*target\s*pengiriman/i', $evidenceLower)) {
                $datePattern = '/\b(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}|\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\b/';
                if (preg_match($datePattern, $evidence, $matches)) {
                    $dateStr = $matches[0];
                    $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-y', 'd/m/y'];
                    foreach ($formats as $format) {
                        $date = \DateTime::createFromFormat($format, $dateStr);
                        if ($date && $date->format($format) === $dateStr) {
                            return ['is_valid' => true, 'message' => 'Sistem: Target pengiriman valid (' . $date->format('d-m-Y') . ')'];
                        }
                    }
                }
                return ['is_valid' => true, 'message' => 'Sistem: Target pengiriman sudah ada'];
            }

            // Extract date patterns - more flexible
            $datePatterns = [
                '/\b(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})\b/', // DD-MM-YYYY or DD/MM/YYYY
                '/\b(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\b/', // YYYY-MM-DD or YYYY/MM/DD
                '/\b(\d{1,2}\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\s+\d{4})\b/i', // DD Month YYYY
                '/\b((januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\s+\d{1,2},?\s+\d{4})\b/i', // Month DD, YYYY
            ];

            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $evidence, $matches)) {
                    $dateStr = $matches[0];
                    $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-y', 'd/m/y', 'Y/m/d'];
                    foreach ($formats as $format) {
                        $date = \DateTime::createFromFormat($format, $dateStr);
                        if ($date && $date->format($format) === $dateStr) {
                            return ['is_valid' => true, 'message' => 'Sistem: Tanggal valid (' . $date->format('d-m-Y') . ')'];
                        }
                    }
                    // Try to parse Indonesian month names
                    if (preg_match('/(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\s+(\d{4})/i', $dateStr, $dateMatches)) {
                        $day = (int)$dateMatches[1];
                        $monthName = strtolower($dateMatches[2]);
                        $year = (int)$dateMatches[3];

                        $monthMap = [
                            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
                            'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12
                        ];

                        if (isset($monthMap[$monthName])) {
                            $month = $monthMap[$monthName];
                            if (checkdate($month, $day, $year)) {
                                return ['is_valid' => true, 'message' => 'Sistem: Tanggal valid (' . sprintf('%02d-%02d-%04d', $day, $month, $year) . ')'];
                            }
                        }
                    }
                }
            }

            // FRASE: "Tidak ada" = REJECTED
            if (preg_match('/^(tidak\s*ada|-)$/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Tanggal tidak tersedia'];
            }

            return ['is_valid' => false, 'message' => 'Sistem: Format tanggal tidak valid. Contoh: 24-01-2026, 24 Januari 2026, atau "Sudah ada target pengiriman 24-01-2026"'];
        }

        if ($inputType === 'number') {
            if ($evidence === null || $evidence === '') return ['is_valid' => false, 'message' => 'Angka tidak boleh kosong'];

            // More flexible number matching
            $numberPatterns = [
                '/\b(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?)\b/', // Standard numbers with commas/decimals
                '/\b(\d+(?:[.,]\d+)?)\b/', // Simple decimal numbers
                '/\b(\d+)\b/', // Whole numbers
            ];

            foreach ($numberPatterns as $pattern) {
                if (preg_match($pattern, $evidence, $matches)) {
                    $number = $matches[0];
                    $normalizedNumber = str_replace(',', '.', $number);
                    if (is_numeric($normalizedNumber)) {
                        $numValue = floatval($normalizedNumber);
                        if ($numValue >= 0) {
                            return ['is_valid' => true, 'message' => 'Sistem: Angka valid (' . $number . ')'];
                        }
                    }
                }
            }

            return ['is_valid' => false, 'message' => 'Sistem: Tidak ditemukan angka yang valid. Contoh: 150, 150.5, atau 1,500'];
        }

        if ($inputType === 'currency') {
            if ($evidence === null || $evidence === '') return ['is_valid' => false, 'message' => 'Nominal tidak boleh kosong'];

            $evidenceLower = strtolower($evidence);

            // FRASE: "Belum ada target harga/nominal" = REJECTED
            if (preg_match('/belum\s*(ada)?\s*target\s*(harga|nominal|price)/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Target harga belum ada'];
            }

            // FRASE: "Belum ada nego" = REJECTED
            if (preg_match('/belum\s*(ada)?\s*(nego|negosiasi)/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Negosiasi belum selesai'];
            }

            // FRASE: "Free/Gratis" = APPROVED
            if (preg_match('/(free|gratis|tanpa\s*biaya)/i', $evidenceLower)) {
                return ['is_valid' => true, 'message' => 'Sistem: Layanan Gratis'];
            }

            // FRASE: "Sudah ada target harga/nominal" = APPROVED
            if (preg_match('/sudah\s*(ada)?\s*target\s*(harga|nominal)/i', $evidenceLower)) {
                $currencyPattern = '/(?:Rp\.?\s*)?(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)/i';
                if (preg_match($currencyPattern, $evidence, $matches)) {
                    $amount = $matches[1];
                    $cleanAmount = preg_replace('/[.,]/', '', $amount);
                    if (is_numeric($cleanAmount) && $cleanAmount > 0) {
                        $formattedAmount = 'Rp ' . number_format((int)$cleanAmount, 0, ',', '.');
                        return ['is_valid' => true, 'message' => 'Sistem: Target harga valid (' . $formattedAmount . ')'];
                    }
                }
                return ['is_valid' => true, 'message' => 'Sistem: Target harga sudah ada'];
            }

            // FRASE: "Sudah nego" = APPROVED
            if (preg_match('/sudah\s*(di)?\s*(nego|negosiasi)/i', $evidenceLower)) {
                return ['is_valid' => true, 'message' => 'Sistem: Negosiasi sudah selesai'];
            }

            // FRASE: "Belum tahu" = REJECTED
            if (preg_match('/belum\s*tahu/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Nominal belum diketahui'];
            }

            // FRASE: "Sedang dalam nego" = REJECTED
            if (preg_match('/masih\s*(dalam\s*)?(nego|negosiasi)/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Negosiasi masih berlangsung'];
            }

            // More flexible currency pattern matching
            $currencyPatterns = [
                '/(?:Rp\.?\s*)?(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)/i', // Standard Rupiah format
                '/(?:IDR?\.?\s*)?(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)/i', // IDR format
                '/(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)\s*(?:rupiah|rb|jt|juta|milyar)/i', // With currency words
            ];

            foreach ($currencyPatterns as $pattern) {
                if (preg_match($pattern, $evidence, $matches)) {
                    $amount = $matches[1];
                    $cleanAmount = preg_replace('/[.,]/', '', $amount);
                    if (is_numeric($cleanAmount) && $cleanAmount > 0) {
                        $formattedAmount = 'Rp ' . number_format((int)$cleanAmount, 0, ',', '.');
                        return ['is_valid' => true, 'message' => 'Sistem: Nominal valid (' . $formattedAmount . ')'];
                    }
                }
            }

            if (preg_match('/^(tidak\s*ada|-)$/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Sistem: Nominal tidak tersedia'];
            }

            return ['is_valid' => false, 'message' => 'Sistem: Format nominal tidak valid. Contoh: Rp 10.000.000, 10jt, atau "Sudah ada target harga Rp 10.000.000"'];
        }

        if (in_array($inputType, ['file', 'image', 'video'])) {
            if (!$file) return ['is_valid' => false, 'message' => 'File belum diupload'];
            
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

        if ($inputType === 'text') {
            if ($evidence === null || $evidence === '' || strlen(trim($evidence)) < 5) {
                return ['is_valid' => false, 'message' => 'Sistem: Jawaban terlalu pendek (min 5 karakter)'];
            }

            // Special handling for specific daily goals
            $description = strtolower($dailyGoal->description);

            // Handle the specific C&I coordination daily goal
            if (preg_match('/koordinasi dengan customer dokumen apa saja yang harus disiapkan/i', $description) ||
                preg_match('/untuk customer c&i pastikan user sudah persiapkan/i', $description)) {

                $evidenceLower = strtolower($evidence);

                // Check for document mentions
                $documentKeywords = [
                    'surat jalan', 'sj', 'invoice', 'inv', 'invoicing', 'bast', 'berita acara serah terima',
                    'dokumen', 'document', 'persiapan', 'listrik', 'forklift', 'electricity', 'ketersediaan'
                ];

                $documentCount = 0;
                foreach ($documentKeywords as $keyword) {
                    if (str_contains($evidenceLower, $keyword)) {
                        $documentCount++;
                    }
                }

                // Check for completion indicators
                $completionIndicators = [
                    'sudah', 'selesai', 'siap', 'tersedia', 'available', 'ready', 'prepared',
                    'sudah koordinasi', 'sudah komunikasi', 'sudah konfirmasi'
                ];

                $completionCount = 0;
                foreach ($completionIndicators as $indicator) {
                    if (str_contains($evidenceLower, $indicator)) {
                        $completionCount++;
                    }
                }

                if ($documentCount >= 2 && $completionCount >= 1) {
                    return ['is_valid' => true, 'message' => 'Sistem: Koordinasi dokumen sudah dilakukan dengan baik'];
                } elseif ($documentCount >= 1) {
                    return ['is_valid' => true, 'message' => 'Sistem: Ada indikasi koordinasi dokumen'];
                } else {
                    return ['is_valid' => false, 'message' => 'Sistem: Jawaban kurang spesifik tentang dokumen yang dikoordinasikan'];
                }
            }

            try {
                $openaiApiKey = env('OPENAI_API_KEY');
                if (!$openaiApiKey) {
                    return $this->fallbackTextValidation($evidence, $dailyGoal);
                }

                $prompt = "Analyze if the following user input indicates successful completion of the mission: '{$dailyGoal->description}'. User input: '{$evidence}'. Respond with only 'APPROVED' or 'REJECTED' followed by a brief reason (max 50 words).";

                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $openaiApiKey, 'Content-Type' => 'application/json'])->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
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
                return $this->fallbackTextValidation($evidence, $dailyGoal);
            } catch (\Exception $e) {
                Log::error('OpenAI API error: ' . $e->getMessage());
                return $this->fallbackTextValidation($evidence, $dailyGoal);
            }
        }

        return ['is_valid' => true, 'message' => 'Sistem: Data diterima'];
    }

    private function fallbackTextValidation($evidence, $dailyGoal)
    {
        $evidenceText = strtolower($evidence);
        $completionIndicators = ['sudah selesai', 'berhasil', 'selesai', 'disetujui', 'approved', 'ok', 'oke', 'deal', 'setuju', 'lancar', 'sukses', 'tercapau', 'terpenuhi', 'memenuhi', 'bagus', 'baik', 'mantap', 'siap', 'done'];
        $failureIndicators = ['belum', 'gagal', 'tidak', 'kurang', 'belum selesai', 'belum berhasil', 'belum tercapai'];

        $completionCount = 0;
        $failureCount = 0;

        foreach ($completionIndicators as $indicator) {
            if (str_contains($evidenceText, $indicator)) $completionCount++;
        }

        foreach ($failureIndicators as $indicator) {
            if (str_contains($evidenceText, $indicator)) $failureCount++;
        }

        $sentences = preg_split('/[.!?\n]+/', $evidenceText);
        $positiveSentences = 0;
        $negativeSentences = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            $sentenceCompletion = 0;
            $sentenceFailure = 0;

            foreach ($completionIndicators as $indicator) {
                if (str_contains($sentence, $indicator)) $sentenceCompletion++;
            }

            foreach ($failureIndicators as $indicator) {
                if (str_contains($sentence, $indicator)) $sentenceFailure++;
            }

            if ($sentenceCompletion > $sentenceFailure) {
                $positiveSentences++;
            } elseif ($sentenceFailure > $sentenceCompletion) {
                $negativeSentences++;
            }
        }

        if ($completionCount > $failureCount && $positiveSentences >= $negativeSentences) {
            return ['is_valid' => true, 'message' => 'Sistem: Indikasi penyelesaian misi terdeteksi'];
        } elseif ($failureCount > $completionCount || $negativeSentences > $positiveSentences) {
            return ['is_valid' => false, 'message' => 'Sistem: Misi belum terpenuhi berdasarkan penjelasan'];
        }

        if (strlen(trim($evidence)) >= 20) {
            return ['is_valid' => true, 'message' => 'Sistem: Jawaban diterima (perlu verifikasi manual)'];
        }

        return ['is_valid' => false, 'message' => 'Sistem: Jawaban kurang detail tentang penyelesaian misi'];
    }

    public function revert(Request $request, $progressId)
    {
        $actor = $request->user();

        $progress = Progress::findOrFail($progressId);

        // Validate that the progress belongs to the user and is approved
        if ($progress->user_id !== $actor->id) {
            return response()->json(['status' => false, 'message' => 'Unauthorized to revert this progress.'], 403);
        }

        if ($progress->status !== 'approved') {
            return response()->json(['status' => false, 'message' => 'Only approved progress can be reverted.'], 400);
        }

        DB::beginTransaction();
        try {
            // Set progress_value to 0 and status to 'rejected' (since 'reverted' is not in enum)
            $progress->update([
                'progress_value' => 0,
                'status' => 'rejected',
                'reviewer_note' => 'Reverted by user'
            ]);

            // Recalculate customer progress percentage
            $customer = Customer::findOrFail($progress->customer_id);
            $currentKpiId = $customer->current_kpi_id ?? $progress->kpi_id;

            $categoryToTypeMapping = ['Pendidikan' => 1, 'Pemerintah' => 2, 'Web Inquiry Corporate' => 3, 'Web Inquiry CNI' => 4, 'Web Inquiry C&I' => 4];
            $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;

            $totalAssignedQuery = DailyGoal::where('user_id', $actor->id)->where('kpi_id', $currentKpiId)->where('description', 'NOT LIKE', 'Auto-generated%');

            if (strtolower($customer->category) === 'pemerintah') {
                $groupMapping = ['UKPBJ' => 'KEDINASAN', 'RUMAH SAKIT' => 'KEDINASAN', 'KANTOR KEDINASAN' => 'KEDINASAN', 'KANTOR BALAI' => 'KEDINASAN', 'KELURAHAN' => 'KECAMATAN', 'KECAMATAN' => 'KECAMATAN', 'PUSKESMAS' => 'PUSKESMAS'];
                $rawSub = strtoupper($customer->sub_category ?? '');
                $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;
                $totalAssignedQuery->where('sub_category', $targetGoalGroup);
            } else {
                $totalAssignedQuery->where('daily_goal_type_id', $expectedTypeId);
            }

            $totalAssigned = $totalAssignedQuery->count();

            $totalProgressValue = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id)
                ->where('status', 'approved')
                ->whereNotNull('time_completed')
                ->sum('progress_value');

            $currentProgress = $totalAssigned > 0 ? round(($totalProgressValue / 100) * 100, 2) : 0;

            // Recalculate scores using ScoringService
            $scoringResult = $this->scoringService->calculateKpiScore($customer->id, $progress->kpi_id, $actor->id);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Progress reverted successfully.',
                'progress_percent' => $currentProgress,
                'scoring' => $scoringResult
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress revert error: " . $e->getMessage(), ['progress_id' => $progressId]);
            return response()->json(['status' => false, 'message' => 'Failed to revert progress.'], 500);
        }
    }

    public function getCustomerProgress(Request $request, $customerId)
    {
        $actor = $request->user();
        $customer = Customer::findOrFail($customerId);

        $progresses = Progress::where('customer_id', $customerId)
            ->where('user_id', $actor->id)
            ->with(['dailyGoal', 'kpi', 'attachments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['status' => true, 'data' => $progresses]);
    }
}

