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

class ProgressController extends Controller
{
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
            return response()->json(['message' => 'Misi ini sudah diselesaikan sebelumnya.'], 409);
        }

        DB::beginTransaction();
        try {
            // 2. HITUNG BOBOT PROGRESS
            $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->where('description', 'NOT LIKE', 'Auto-generated%') // ← PENTING!
                ->count();
            $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

            // 3. VALIDASI SEDERHANA
            $isValid = true; 
            $reviewNote = "Sistem: Data diterima otomatis.";

            if ($dailyGoal->input_type === 'phone' && strlen($request->evidence) < 10) {
                $isValid = false;
                $reviewNote = "Sistem: Format nomor telepon tidak valid.";
            }

            // 4. SIMPAN PROGRESS
            $progress = Progress::create([
                'user_id' => $actor->id,
                'kpi_id' => $dailyGoal->kpi_id,
                'daily_goal_id' => $dailyGoal->id,
                'customer_id' => $customer->id,
                'time_completed' => $isValid ? now() : null,
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
            } elseif ($request->evidence) {
                ProgressAttachment::create([
                    'progress_id' => $progress->id,
                    'content' => $request->evidence,
                    'type' => $dailyGoal->input_type
                ]);
            }

            // 6. CEK APAKAH KPI CURRENT SUDAH 100%
            $currentKpiId = $customer->current_kpi_id ?? $dailyGoal->kpi_id; // ← Fallback ke kpi_id jika current_kpi_id null
            
            $totalAssigned = DailyGoal::where('user_id', $actor->id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();

            $totalCompleted = Progress::where('customer_id', $customer->id)
                ->where('kpi_id', $currentKpiId)
                ->where('user_id', $actor->id) // ← TAMBAHKAN ini untuk memastikan hanya progress user ini
                ->whereNotNull('time_completed')
                ->distinct('daily_goal_id') // ← Pastikan tidak dobel count
                ->count('daily_goal_id');

            \Log::info("KPI Check", [
                'customer_id' => $customer->id,
                'current_kpi_id' => $currentKpiId,
                'total_assigned' => $totalAssigned,
                'total_completed' => $totalCompleted
            ]);

            // 7. JIKA SUDAH 100%, NAIK KE KPI BERIKUTNYA
            $isFinished = ($totalAssigned > 0 && $totalCompleted >= $totalAssigned);

            if ($isFinished) {
                $currentKpi = KPI::find($currentKpiId);
                
                // Cari KPI berikutnya berdasarkan sequence
                $nextKpi = KPI::where('sequence', '>', $currentKpi->sequence)
                    ->orderBy('sequence', 'asc')
                    ->first();

                if ($nextKpi) {
                    // UPDATE CUSTOMER
                    $customer->current_kpi_id = $nextKpi->id;
                    $customer->kpi_id = $nextKpi->id; // ← Update juga kpi_id kalau masih dipakai
                    
                    // ⭐ MAPPING STATUS BERDASARKAN CODE KPI
                    $statusMap = [
                        'visit1' => 'New',
                        'visit2' => 'Warm Prospect',
                        'visit3' => 'Hot Prospect',
                        'deal' => 'Deal Won',
                        'after_sales' => 'After Sales'
                    ];
                    
                    $customer->status = $statusMap[$nextKpi->code] ?? $customer->status;
                    $customer->status_changed_at = now();
                    $customer->save();

                    \Log::info("Customer advanced to next KPI", [
                        'customer_id' => $customer->id,
                        'old_kpi' => $currentKpi->code,
                        'new_kpi' => $nextKpi->code,
                        'new_status' => $customer->status
                    ]);
                }
            }

            DB::commit();
            
            return response()->json([
                'status' => true, 
                'is_valid' => $isValid, 
                'message' => $reviewNote,
                'kpi_completed' => $isFinished,
                'progress_percent' => $totalAssigned > 0 ? round(($totalCompleted / $totalAssigned) * 100, 2) : 0
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Progress store error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
