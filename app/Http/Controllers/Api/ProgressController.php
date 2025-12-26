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
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'daily_goal_id' => 'required|integer|exists:daily_goals,id',
            'customer_id' => 'required|integer|exists:customers,id',
            // evidence can be file or content depending on daily goal
            'evidence' => 'sometimes',
            'evidence_type' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation errors', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $dailyGoal = DailyGoal::findOrFail($data['daily_goal_id']);
        $customer = Customer::findOrFail($data['customer_id']);

        // Authorization: actor must be owner (sales) or admin/manager
        if ($actor->role !== 'administrator' && $actor->role !== 'sales_manager' && $dailyGoal->user_id !== $actor->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Ensure the daily goal belongs to same KPI as customer's current KPI
        if ($customer->current_kpi_id && $customer->current_kpi_id != $dailyGoal->kpi_id) {
            return response()->json(['message' => 'Daily goal does not match customer current KPI.'], 422);
        }

        // Prevent duplicate completion for same daily goal and customer
        $exists = Progress::where('user_id', $dailyGoal->user_id)
            ->where('customer_id', $customer->id)
            ->where('daily_goal_id', $dailyGoal->id)
            ->exists();
        if ($exists) {
            return response()->json(['status' => false, 'message' => 'This daily goal has already been completed for this customer.'], 409);
        }

        // compute progress value as equal share of KPI (100 / number of daily goals assigned to this user for this KPI)
        $totalDaily = DailyGoal::where('user_id', $dailyGoal->user_id)->where('kpi_id', $dailyGoal->kpi_id)->count();
        $progressValue = $totalDaily ? round(100 / $totalDaily, 2) : 0;

        DB::beginTransaction();
        try {
            $progress = Progress::create([
                'user_id' => $dailyGoal->user_id,
                'kpi_id' => $dailyGoal->kpi_id,
                'daily_goal_id' => $dailyGoal->id,
                'customer_id' => $customer->id,
                'time_completed' => now(),
                'progress_value' => $progressValue,
                'progress_date' => now()->toDateString(),
            ]);

            // Handle evidence
            $attachment = null;
            if ($request->hasFile('evidence')) {
                $file = $request->file('evidence');
                $path = $file->store('progress_attachments', 'public');

                $attachment = ProgressAttachment::create([
                    'progress_id' => $progress->id,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'type' => $dailyGoal->input_type === 'image' ? 'image' : ($dailyGoal->input_type === 'video' ? 'video' : 'file'),
                ]);
            } else {
                // handle textual/phone evidence in 'evidence' field
                if (! empty($data['evidence'])) {
                    $attachment = ProgressAttachment::create([
                        'progress_id' => $progress->id,
                        'original_name' => null,
                        'file_path' => null,
                        'mime_type' => null,
                        'size' => null,
                        'type' => $data['evidence_type'] ?? ($dailyGoal->input_type === 'phone' ? 'phone' : 'text'),
                        'content' => is_array($data['evidence']) ? json_encode($data['evidence']) : (string)$data['evidence'],
                    ]);
                }
            }

            // Recompute cumulative percent for this user/customer/kpi
            $cumulative = Progress::where('user_id', $dailyGoal->user_id)
                ->where('customer_id', $customer->id)
                ->where('kpi_id', $dailyGoal->kpi_id)
                ->sum('progress_value');

            $cumulative = min(100, round($cumulative, 2));

            // If KPI completed, advance to next KPI in sequence (cycle type)
            $kpiCompleted = $cumulative >= 100;
            $statusUpdate = null;
            $nextKpi = null;
            if ($kpiCompleted) {
                $currentKpi = KPI::find($dailyGoal->kpi_id);
                if ($currentKpi) {
                    // find next KPI by sequence
                    $nextKpi = KPI::where('type', 'cycle')
                        ->where('sequence', '>', $currentKpi->sequence ?? 0)
                        ->orderBy('sequence', 'asc')
                        ->first();

                    if ($nextKpi) {
                        $customer->current_kpi_id = $nextKpi->id;
                    } else {
                        // no next KPI in cycle -> we're in After Sales
                        $customer->current_kpi_id = null;
                    }

                    // Map KPI id (or sequence) to status labels using typical rules
                    $statusMap = [
                        2 => 'Warm Prospect',
                        3 => 'Hot Prospect',
                        4 => 'Deal Won',
                        6 => 'After Sales',
                    ];
                    $newStatus = null;
                    if ($nextKpi) {
                        $newStatus = $statusMap[$nextKpi->id] ?? null;
                    } else {
                        // If no next KPI assume After Sales
                        $newStatus = 'After Sales';
                    }

                    if ($newStatus) {
                        $customer->status = $newStatus;
                        $customer->status_changed_at = now();
                        $statusUpdate = $newStatus;
                    }

                    $customer->save();
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Progress recorded',
                'progress' => $progress,
                'attachment' => $attachment,
                'cumulative_percent' => $cumulative,
                'kpi_completed' => $kpiCompleted,
                'next_kpi' => $nextKpi ? $nextKpi->only(['id','code','description','sequence']) : null,
                'status_update' => $statusUpdate,
                'can_reset_cycle' => ($statusUpdate === 'After Sales') ? true : false,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Could not record progress', 'error' => $e->getMessage()], 500);
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
