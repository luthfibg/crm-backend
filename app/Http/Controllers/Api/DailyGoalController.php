<?php

namespace App\Http\Controllers\Api;

use App\Models\Progress;
use App\Models\DailyGoal;
use App\Models\User;
use App\Models\KPI;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB; 

class DailyGoalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
{
    $user = $request->user();
    if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);

    // 1. Ambil customer
    if ($user->role === 'administrator') {
        $customers = \App\Models\Customer::with('kpi')->get();
    } else {
        $customers = $user->customers()->with('kpi')->get();
    }

    // 2. Hitung jumlah daily goals per KPI
    $assignedCounts = DailyGoal::where('user_id', $user->id)
        ->where('description', 'NOT LIKE', 'Auto-generated%')
        ->selectRaw('kpi_id, COUNT(*) as total')
        ->groupBy('kpi_id')
        ->pluck('total', 'kpi_id');

    // 3. ⭐ PERBAIKAN: Ambil data completed HANYA yang status = 'approved'
    $completedRows = Progress::where('user_id', $user->id)
        ->where('status', 'approved') // ← TAMBAHKAN INI
        ->whereNotNull('time_completed')
        ->whereIn('customer_id', $customers->pluck('id'))
        ->selectRaw('kpi_id, customer_id, COUNT(DISTINCT daily_goal_id) as completed')
        ->groupBy('kpi_id', 'customer_id')
        ->get();

    $completedByCustomer = $completedRows->groupBy('customer_id')->map(function($g) {
        return $g->keyBy('kpi_id')->map(fn($r) => (int)$r->completed);
    });

    $result = $customers->map(function($customer) use ($user, $assignedCounts, $completedByCustomer) {
        $currentKpi = $customer->kpi;
        $currentKpiId = $customer->current_kpi_id;

        // Ambil semua KPI yang sudah dilewati
        $allKpis = KPI::where('type', 'cycle')
            ->where('sequence', '<=', $currentKpi->sequence ?? 1)
            ->orderBy('sequence', 'asc')
            ->get();

        $kpiProgress = $allKpis->map(function($kpi) use ($user, $customer, $assignedCounts, $completedByCustomer, $currentKpiId) {
            $assigned = $assignedCounts[$kpi->id] ?? 0;
            $completedCount = $completedByCustomer[$customer->id][$kpi->id] ?? 0;
            $percent = $assigned > 0 ? min(100, round(($completedCount / $assigned) * 100, 2)) : 0;

            return [
                'kpi_id' => $kpi->id,
                'kpi_code' => $kpi->code,
                'kpi_description' => $kpi->description,
                'assigned_count' => (int) $assigned,
                'completed_count' => (int) $completedCount,
                'percent' => (float) $percent,
                'is_current' => $kpi->id === $currentKpiId,
                'is_completed' => $percent >= 100,
            ];
        });

        // Stats untuk KPI current saja
        $assigned = $currentKpiId ? ($assignedCounts[$currentKpiId] ?? 0) : 0;
        $completedCount = $completedByCustomer[$customer->id][$currentKpiId] ?? 0;
        $percent = $assigned > 0 ? min(100, round(($completedCount / $assigned) * 100, 2)) : 0;
        $actualPoint = ($percent / 100) * ($currentKpi->weight_point ?? 0);

        // Daily goals untuk KPI current
        $dailyGoals = $currentKpiId
            ? DailyGoal::where('user_id', $user->id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->get(['id','description','input_type','order','evidence_required'])
                ->map(function($goal) use ($customer) {
                    // ⭐ PERBAIKAN: Cek hanya yang approved
                    $isDone = Progress::where('daily_goal_id', $goal->id)
                        ->where('customer_id', $customer->id)
                        ->where('status', 'approved') // ← TAMBAHKAN INI
                        ->whereNotNull('time_completed')
                        ->exists();
                    $goal->is_completed = $isDone;
                    return $goal;
                }) : collect();

        return [
            'customer' => [
                'id' => $customer->id,
                'pic' => $customer->pic,
                'institution' => $customer->institution,
                'status' => $customer->status,
                'email' => $customer->email,
                'phone' => $customer->phone_number,
            ],
            'kpi' => $currentKpi ? $currentKpi->only(['id','code','description','weight_point']) : null,
            'kpi_progress_history' => $kpiProgress,
            'daily_goals' => $dailyGoals,
            'stats' => [
                'assigned_count' => (int) $assigned,
                'completed_count' => (int) $completedCount,
                'percent' => (float) $percent,
                'actual_point' => (float) $actualPoint,
            ],
        ];
    });

    return response()->json(['data' => $result]);
}

    /**
     * Store a new daily goals per KPI for new sales.
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only managers or administrators can create daily goals for other users
        if (! in_array($actor->role, ['administrator', 'sales_manager'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'kpi_id' => 'required|integer|exists:kpis,id',
            'daily_goals' => 'required|array|min:1',
            'daily_goals.*.description' => 'required|string|max:255',
            'daily_goals.*.is_completed' => 'sometimes|boolean',
            'daily_goals.*.input_type' => 'sometimes|string|in:none,text,phone,file,image,video',
            'daily_goals.*.order' => 'sometimes|integer',
            'daily_goals.*.evidence_required' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $user = User::findOrFail($data['user_id']);
        $kpi = KPI::findOrFail($data['kpi_id']);

        DB::beginTransaction();
        try {
            // Ensure the KPI is attached to the user (sales)
            $user->kpis()->syncWithoutDetaching([$kpi->id]);

            $created = [];
            foreach ($data['daily_goals'] as $dg) {
                $created[] = DailyGoal::create([
                    'description' => $dg['description'],
                    'user_id' => $user->id,
                    'kpi_id' => $kpi->id,
                    'is_completed' => $dg['is_completed'] ?? false,
                    'input_type' => $dg['input_type'] ?? 'none',
                    'order' => $dg['order'] ?? null,
                    'evidence_required' => $dg['evidence_required'] ?? false,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Daily goals created',
                'daily_goals' => $created
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Could not create daily goals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // not used right now
    }

    /**
     * Get all daily goals for a given user and kpi.
     */
    public function byUserKpi(Request $request, $userId, $kpiId)
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // allow administrator, sales_manager, or the user themselves
        if (! in_array($actor->role, ['administrator', 'sales_manager']) && $actor->id != (int)$userId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $dailyGoals = DailyGoal::where('user_id', $userId)
            ->where('kpi_id', $kpiId)
            ->get(['id','description','input_type','order','evidence_required']);

        return response()->json(['data' => $dailyGoals]);
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
