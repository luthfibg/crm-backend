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

        // customers milik sales beserta KPI
        $customers = $user->customers()->with('kpi')->get();

        // hitung jumlah daily goals yang di-assign per KPI (sekali query)
        $assignedCounts = DailyGoal::where('user_id', $user->id)
            ->selectRaw('kpi_id, COUNT(*) as total')
            ->groupBy('kpi_id')
            ->pluck('total', 'kpi_id');

        // hitung completed per (customer, kpi) (misal: time_completed != null)
        $completedRows = Progress::where('user_id', $user->id)
            ->whereNotNull('time_completed')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->selectRaw('kpi_id, customer_id, COUNT(DISTINCT daily_goal_id) as completed')
            ->groupBy('kpi_id', 'customer_id')
            ->get();

        // group hasil completed untuk lookup cepat
        $completedByCustomer = $completedRows->groupBy('customer_id')->map(function($g) {
            return $g->keyBy('kpi_id')->map(fn($r) => (int)$r->completed);
        });

        $result = $customers->map(function($customer) use ($user, $assignedCounts, $completedByCustomer) {
            $kpi = $customer->kpi;
            $kpiId = $kpi->id ?? null;

            $assigned = $kpiId ? ($assignedCounts[$kpiId] ?? 0) : 0;
            $completedCount = $completedByCustomer[$customer->id][$kpiId] ?? 0;

            $percent = $assigned ? min(100, round(($completedCount / $assigned) * 100, 2)) : 0;
            $actualPoint = ($percent / 100) * ($kpi->weight_point ?? 0);

            $dailyGoals = $kpiId
                ? DailyGoal::where('user_id', $user->id)->where('kpi_id', $kpiId)->get(['id','description','is_completed'])
                : collect();

            return [
                'customer' => $customer->only(['id','name','institution']),
                'kpi' => $kpi ? $kpi->only(['id','code','description','weight_point']) : null,
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
