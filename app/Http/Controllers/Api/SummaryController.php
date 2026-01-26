<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSummary;
use App\Models\KPI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SummaryController extends Controller
{
    /**
     * Store or update summary for a customer.
     * This will replace any existing summary for this customer.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'summary' => 'required|string|min:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $customer = Customer::findOrFail($request->customer_id);
        $currentKpiId = $customer->current_kpi_id ?? $customer->kpi_id;

        // Check if customer exists and has progress 100%
        $existingSummary = CustomerSummary::where('customer_id', $customer->id)->first();

        DB::beginTransaction();
        try {
            $summary = CustomerSummary::updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'user_id' => $actor->id,
                    'kpi_id' => $currentKpiId,
                    'summary' => $request->summary,
                ]
            );

            Log::info("Summary saved for customer", [
                'customer_id' => $customer->id,
                'kpi_id' => $currentKpiId,
                'user_id' => $actor->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Kesimpulan berhasil disimpan',
                'data' => $summary
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error saving summary: " . $e->getMessage(), [
                'customer_id' => $customer->id,
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Terjadi kesalahan saat menyimpan kesimpulan. Silakan coba lagi.'
            ], 500);
        }
    }

    /**
     * Store summary and advance customer to next KPI.
     * This is called after all missions are completed and user submits summary.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitAndAdvance(Request $request)
    {
        $actor = $request->user();
        
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'summary' => 'required|string|min:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $customer = Customer::findOrFail($request->customer_id);
        $currentKpiId = $customer->current_kpi_id ?? $customer->kpi_id;
        $currentKpi = KPI::find($currentKpiId);

        if (!$currentKpi) {
            return response()->json([
                'status' => false,
                'message' => 'KPI tidak ditemukan'
            ], 404);
        }

        DB::beginTransaction();
        try {
            // 1. Save/Update summary
            CustomerSummary::updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'user_id' => $actor->id,
                    'kpi_id' => $currentKpiId,
                    'summary' => $request->summary,
                ]
            );

            // 2. Advance customer to next KPI
            $this->advanceCustomerToNextKPI($customer, $currentKpi);

            Log::info("Summary saved and customer advanced", [
                'customer_id' => $customer->id,
                'old_kpi_id' => $currentKpiId,
                'user_id' => $actor->id,
            ]);

            DB::commit();

            $isFinalKPI = $currentKpi->code === 'after_sales';
            $message = $isFinalKPI
                ? '✅ Selamat! After Sales telah berhasil diselesaikan.\n\nPenjualan berhasil dan akan disimpan ke dalam history.'
                : "✅ Kesimpulan disimpan! KPI {$currentKpi->code} telah diselesaikan.\n\nProspek otomatis naik ke status berikutnya.";

            return response()->json([
                'status' => true,
                'message' => $message,
                'customer' => $customer->fresh(),
                'is_final_kpi' => $isFinalKPI,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error in submitAndAdvance: " . $e->getMessage(), [
                'customer_id' => $customer->id,
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Terjadi kesalahan. Silakan coba lagi.'
            ], 500);
        }
    }

    /**
     * Get summary for a customer.
     *
     * @param Request $request
     * @param int $customerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $customerId)
    {
        $user = $request->user();

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer tidak ditemukan'
            ], 404);
        }

        // Authorization check
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $summary = CustomerSummary::where('customer_id', $customerId)
            ->with(['kpi', 'user'])
            ->first();

        return response()->json([
            'status' => true,
            'data' => $summary
        ]);
    }

    /**
     * Get summary for a customer (alternative route).
     *
     * @param Request $request
     * @param int $customerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function showByCustomer(Request $request, $customerId)
    {
        $user = $request->user();

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer tidak ditemukan'
            ], 404);
        }

        // Authorization check
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $summary = CustomerSummary::where('customer_id', $customerId)
            ->where('kpi_id', $customer->current_kpi_id ?? $customer->kpi_id)
            ->first();

        return response()->json([
            'status' => true,
            'summary' => $summary ? $summary->summary : null
        ]);
    }

    /**
     * Update progress values to full after summary submission.
     */
    private function updateProgressValuesAfterSummary($customerId, $kpiId, $userId)
    {
        $totalDaily = \App\Models\DailyGoal::where('user_id', $userId)
            ->where('kpi_id', $kpiId)
            ->where('description', 'NOT LIKE', 'Auto-generated%')
            ->count();

        $fullProgressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

        \App\Models\Progress::where('customer_id', $customerId)
            ->where('kpi_id', $kpiId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->update(['progress_value' => $fullProgressValue]);

        Log::info("Progress values updated after summary submission", [
            'customer_id' => $customerId,
            'kpi_id' => $kpiId,
            'progress_value' => $fullProgressValue
        ]);
    }

    /**
     * Advance customer to next KPI or mark as completed.
     */
    private function advanceCustomerToNextKPI($customer, $currentKpi)
    {
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

            Log::info("✅ Customer advanced to next KPI after summary", [
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

            Log::info("✅ Customer sale completed after summary", [
                'customer_id' => $customer->id,
                'final_kpi' => $currentKpi->code,
                'status' => 'Completed'
            ]);
        }
    }
}

