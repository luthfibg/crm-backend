<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Progress;
use App\Models\ProgressAttachment;
use App\Models\DailyGoal;
use App\Models\Customer;
use App\Services\ScoringService;

class ProgressController extends Controller
{
    protected $scoringService;

    public function __construct(ScoringService $scoringService)
    {
        $this->scoringService = $scoringService;
    }

    public function store(Request $request)
    {
        $actor = $request->user();

        $validator = Validator::make($request->all(), [
            'daily_goal_id' => 'required|integer|exists:daily_goals,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'evidence' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $dailyGoal = DailyGoal::findOrFail($request->daily_goal_id);
        $customer = Customer::findOrFail($request->customer_id);

        $existingProgress = Progress::where('customer_id', $customer->id)
            ->where('daily_goal_id', $dailyGoal->id)
            ->first();

        if ($existingProgress && $existingProgress->status === 'approved') {
            return response()->json(['status' => false, 'is_valid' => false, 'message' => 'Misi sudah diselesaikan'], 409);
        }

        return $this->saveProgress($request, $dailyGoal, $customer, $actor);
    }

    private function calculateProgressValue($userId, $kpiId)
    {
        $totalDaily = DailyGoal::where('user_id', $userId)
            ->where('kpi_id', $kpiId)
            ->where('description', 'NOT LIKE', 'Auto-generated%')
            ->count();
        return $totalDaily ? round(100 / $totalDaily, 2) : 0;
    }

    private function saveProgress($request, $dailyGoal, $customer, $actor)
    {
        DB::beginTransaction();
        try {
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];
            $reviewNote = $validationResult['message'];

            // Calculate progress value based on total daily goals
            $progressValue = $this->calculateProgressValue($dailyGoal->user_id, $dailyGoal->kpi_id);

            $progress = Progress::create([
                'user_id' => $actor->id,
                'sales_id' => $customer->user_id, // Store sales_id for consistency
                'kpi_id' => $dailyGoal->kpi_id,
                'daily_goal_id' => $dailyGoal->id,
                'customer_id' => $customer->id,
                'time_completed' => now(),
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $reviewNote
            ]);

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

            if ($isValid) {
                $this->scoringService->calculateKpiScore($customer->id, $dailyGoal->kpi_id, $actor->id);
            }

            DB::commit();

            $isKpiCompleted = false;
            if ($isValid) {
                $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id;
                // Use sales_id for consistency
                $totalAssigned = DailyGoal::where('user_id', $customer->user_id)
                    ->where('kpi_id', $currentKpiId)
                    ->where('description', 'NOT LIKE', 'Auto-generated%')
                    ->count();
                $totalApproved = Progress::where('customer_id', $customer->id)
                    ->where('kpi_id', $currentKpiId)
                    ->where('sales_id', $customer->user_id)
                    ->where('status', 'approved')
                    ->count();
                $isKpiCompleted = $totalAssigned > 0 && $totalApproved >= $totalAssigned;
            }

            return response()->json([
                'status' => true,
                'is_valid' => $isValid,
                'message' => $reviewNote,
                'kpi_completed' => $isKpiCompleted,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress store error: " . $e->getMessage());
            return response()->json(['status' => false, 'is_valid' => false, 'error' => 'Terjadi kesalahan'], 500);
        }
    }

    private function validateEvidence($dailyGoal, $request)
    {
        $inputType = $dailyGoal->input_type;
        $evidence = data_get($request->all(), 'evidence');
        $file = $request->file('evidence');

        if ($inputType === 'text') {
            if ($evidence === null || $evidence === '' || strlen(trim($evidence)) < 5) {
                return ['is_valid' => false, 'message' => 'Jawaban terlalu pendek'];
            }
            return $this->smartTextValidation($evidence, $dailyGoal);
        }

        if ($inputType === 'phone') {
            if (!$evidence) return ['is_valid' => false, 'message' => 'Nomor telepon wajib diisi'];
            $cleanPhone = preg_replace('/[^0-9+]/', '', $evidence);
            if (preg_match('/^(\+62|62|0)8[1-9][0-9]{6,9}$/', $cleanPhone)) {
                return ['is_valid' => true, 'message' => 'Nomor telepon valid'];
            }
            return ['is_valid' => false, 'message' => 'Format nomor tidak valid'];
        }

        if ($inputType === 'date') {
            if (!$evidence) return ['is_valid' => false, 'message' => 'Tanggal wajib diisi'];
            $evidenceLower = strtolower($evidence);
            if (preg_match('/belum\s*ada\s*target/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Target belum ada'];
            }
            if (preg_match('/sudah\s*ada\s*target/i', $evidenceLower)) {
                return ['is_valid' => true, 'message' => 'Target sudah ada'];
            }
            $datePatterns = ['/\b(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})\b/', '/\b(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})\b/'];
            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $evidence)) {
                    return ['is_valid' => true, 'message' => 'Format tanggal valid'];
                }
            }
            return ['is_valid' => false, 'message' => 'Format tanggal tidak valid'];
        }

        if ($inputType === 'currency') {
            if (!$evidence) return ['is_valid' => false, 'message' => 'Nominal wajib diisi'];
            $evidenceLower = strtolower($evidence);
            if (preg_match('/belum\s*(ada)?\s*target/i', $evidenceLower)) {
                return ['is_valid' => false, 'message' => 'Target harga belum ada'];
            }
            if (preg_match('/free|gratis|tanpa\s*biaya/i', $evidenceLower)) {
                return ['is_valid' => true, 'message' => 'Layanan Gratis'];
            }
            if (preg_match('/sudah\s*(ada)?\s*target|sudah\s*(di)?\s*nego/i', $evidenceLower)) {
                return ['is_valid' => true, 'message' => 'Sudah ada target/nego'];
            }
            $currencyPattern = '/(?:Rp\.?\s*)?(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)/i';
            if (preg_match($currencyPattern, $evidence, $matches)) {
                $amount = preg_replace('/[.,]/', '', $matches[1]);
                if (is_numeric($amount) && $amount > 0) {
                    return ['is_valid' => true, 'message' => 'Nominal valid'];
                }
            }
            return ['is_valid' => false, 'message' => 'Format nominal tidak valid'];
        }

        if (in_array($inputType, ['file', 'image', 'video'])) {
            if (!$file) return ['is_valid' => false, 'message' => 'File wajib diupload'];
            if ($inputType === 'image') {
                $validMimes = ['image/jpeg', 'image/jpg', 'image/png'];
                if (in_array($file->getMimeType(), $validMimes)) {
                    return ['is_valid' => true, 'message' => 'Gambar valid'];
                }
                return ['is_valid' => false, 'message' => 'File harus gambar (JPG/PNG)'];
            }
            return ['is_valid' => true, 'message' => 'File valid'];
        }

        return ['is_valid' => true, 'message' => 'Data diterima'];
    }

    private function smartTextValidation($evidence, $dailyGoal)
    {
        $evidenceText = strtolower($evidence);
        $completionPatterns = ['sudah melakukan', 'sudah koordinasi', 'sudah komunikasi', 'sudah konfirmasi', 'sudah bertemu'];
        foreach ($completionPatterns as $pattern) {
            if (str_contains($evidenceText, $pattern)) {
                return ['is_valid' => true, 'message' => 'Aktivitas sudah dilakukan'];
            }
        }
        $progressPatterns = ['ada diskusi lanjutan', 'akan dikonsultasikan', 'sedang dalam proses', 'sudah dijadwalkan', 'sedang diproses'];
        foreach ($progressPatterns as $pattern) {
            if (str_contains($evidenceText, $pattern)) {
                return ['is_valid' => true, 'message' => 'Ada aktivitas yang sedang/akan dilakukan'];
            }
        }
        $failurePatterns = ['belum melakukan', 'belum diskusi', 'belum koordinasi', 'tidak jadi', 'ditolak'];
        $hasFailure = false;
        foreach ($failurePatterns as $pattern) {
            if (str_contains($evidenceText, $pattern)) {
                $hasFailure = true;
                break;
            }
        }
        $activityKeywords = ['diskusi', 'koordinasi', 'komunikasi', 'rapat', 'meeting', 'pertemuan'];
        $activityCount = 0;
        foreach ($activityKeywords as $keyword) {
            if (str_contains($evidenceText, $keyword)) {
                $activityCount++;
            }
        }
        $stakeholderKeywords = ['pimpinan', 'kepala', 'direktur', 'manager', 'pbj', 'ppk', 'tim pengadaan', 'pihak terkait'];
        $hasStakeholder = false;
        foreach ($stakeholderKeywords as $keyword) {
            if (str_contains($evidenceText, $keyword)) {
                $hasStakeholder = true;
                break;
            }
        }
        $nextStepPatterns = ['akan ditindaklanjuti', 'akan dilanjutkan', 'selanjutnya'];
        $hasNextStep = false;
        foreach ($nextStepPatterns as $pattern) {
            if (str_contains($evidenceText, $pattern)) {
                $hasNextStep = true;
                break;
            }
        }
        if ($activityCount >= 1 && ($hasStakeholder || $hasNextStep)) {
            return ['is_valid' => true, 'message' => 'Ada indikasi aktivitas dengan pihak terkait'];
        }
        if ($activityCount >= 2) {
            return ['is_valid' => true, 'message' => 'Terdapat aktivitas yang relevan'];
        }
        if ($hasFailure) {
            return ['is_valid' => false, 'message' => 'Aktivitas belum dilakukan'];
        }
        if (strlen(trim($evidence)) >= 50) {
            return ['is_valid' => true, 'message' => 'Jawaban detail diterima'];
        }
        return ['is_valid' => false, 'message' => 'Jawaban kurang detail tentang aktivitas yang dilakukan'];
    }

    public function update(Request $request, $progressId)
    {
        $progress = Progress::findOrFail($progressId);

        if ($progress->status === 'approved') {
            return response()->json(['status' => false, 'is_valid' => false, 'message' => 'Sudah diapprove'], 403);
        }

        $dailyGoal = DailyGoal::findOrFail($progress->daily_goal_id);
        $customer = Customer::findOrFail($progress->customer_id);
        $actor = $request->user();

        DB::beginTransaction();
        try {
            $validationResult = $this->validateEvidence($dailyGoal, $request);
            $isValid = $validationResult['is_valid'];

            // Calculate progress value based on total daily goals (same as store method)
            $progressValue = $this->calculateProgressValue($dailyGoal->user_id, $dailyGoal->kpi_id);

            $progress->update([
                'time_completed' => now(),
                'progress_value' => $isValid ? $progressValue : 0,
                'progress_date' => now()->toDateString(),
                'status' => $isValid ? 'approved' : 'rejected',
                'reviewer_note' => $validationResult['message']
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

            if ($isValid) {
                $this->scoringService->calculateKpiScore($customer->id, $dailyGoal->kpi_id, $actor->id);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'is_valid' => $isValid,
                'message' => $validationResult['message']
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Progress update error: " . $e->getMessage());
            return response()->json(['status' => false, 'error' => 'Terjadi kesalahan'], 500);
        }
    }

    public function revert(Request $request, $progressId)
    {
        $actor = $request->user();
        $progress = Progress::findOrFail($progressId);

        // Use sales_id for consistency check
        $salesId = $progress->sales_id ?? $progress->user_id;
        if ($salesId !== $actor->id) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($progress->status !== 'approved') {
            return response()->json(['status' => false, 'message' => 'Hanya yang approved bisa direvert'], 400);
        }

        $customer = Customer::findOrFail($progress->customer_id);

        DB::beginTransaction();
        try {
            $progress->update([
                'progress_value' => 0,
                'status' => 'rejected',
                'reviewer_note' => 'Reverted by user'
            ]);

            $this->scoringService->calculateKpiScore($customer->id, $progress->kpi_id, $actor->id);

            DB::commit();

            return response()->json(['status' => true, 'message' => 'Berhasil direvert']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Gagal revert'], 500);
        }
    }

    public function getAttachment(Request $request, $progressId)
    {
        $token = $request->get('token');
        if ($token) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel) {
                $user = \App\Models\User::find($tokenModel->tokenable_id);
                if ($user) {
                    $request->setUserResolver(function () use ($user) {
                        return $user;
                    });
                }
            }
        }

        $attachment = ProgressAttachment::where('progress_id', $progressId)->first();

        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        if ($attachment->content) {
            return response()->json([
                'type' => $attachment->type,
                'content' => $attachment->content,
                'original_name' => $attachment->original_name
            ]);
        }

        if ($attachment->file_path) {
            $fullPath = storage_path('app/public/' . $attachment->file_path);

            if (!file_exists($fullPath)) {
                return response()->json(['message' => 'File not found on server'], 404);
            }

            $mimeTypes = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'pdf' => 'application/pdf', 'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'mp4' => 'video/mp4',
            ];

            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';

            $inlineTypes = ['jpg', 'jpeg', 'png', 'pdf', 'mp4'];
            if (in_array($extension, $inlineTypes)) {
                return response()->file($fullPath, ['Content-Type' => $contentType]);
            } else {
                return response()->download($fullPath, $attachment->original_name, ['Content-Type' => $contentType]);
            }
        }

        return response()->json(['message' => 'No file or content available'], 404);
    }
}
