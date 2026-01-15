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
     * Display a listing of prospects dengan progress tracking
     * 
     * ⭐ PERBAIKAN:
     * - Hanya count approved untuk progress
     * - Tambah flag is_rejected untuk tasks yang ditolak
     * - Pastikan UI bisa show rejected tasks untuk di-resubmit
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);

        // 1. Ambil customers (sertakan relasi KPI)
        $query = \App\Models\Customer::with('kpi');
        $customers = ($user->role === 'administrator') ? $query->get() : $user->customers()->with('kpi')->get();

        // 2. ⭐ HITUNG ASSIGNED GOALS SECARA DINAMIS BERDASARKAN TIPE
        // Kita ambil master daily goals yang difilter berdasarkan tipe/kategori
        $allDailyGoals = DailyGoal::where('user_id', $user->id)
            ->where('description', 'NOT LIKE', 'Auto-generated%')
            ->with('type') // Memuat model DailyGoalType
            ->get();

        // 3. Ambil data APPROVED (Tetap sama)
        $approvedRows = Progress::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereNotNull('time_completed')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->selectRaw('kpi_id, customer_id, COUNT(DISTINCT daily_goal_id) as approved')
            ->groupBy('kpi_id', 'customer_id')
            ->get();

        $approvedByCustomer = $approvedRows->groupBy('customer_id')->map(function($g) {
            return $g->keyBy('kpi_id')->map(fn($r) => (int)$r->approved);
        });

        $result = $customers->map(function($customer) use ($user, $allDailyGoals, $approvedByCustomer) {
            $currentKpi = $customer->kpi;
            $currentKpiId = $customer->current_kpi_id;
            $customerCategory = strtolower($customer->category); // Pastikan lowercase untuk perbandingan

            // Fungsi pembantu untuk memfilter goals yang cocok dengan kategori customer ini
            $getGoalsForCustomer = function($kpiId) use ($allDailyGoals, $customerCategory) {
                return $allDailyGoals->filter(function($goal) use ($kpiId, $customerCategory) {
                    // Goal tampil jika: 1. KPI Cocok DAN 2. Tipe cocok dengan kategori customer
                    return $goal->kpi_id == $kpiId && 
                        ($goal->type && strtolower($goal->type->name) === $customerCategory);
                });
            };

            // Ambil semua KPI untuk history
            $allKpis = \App\Models\KPI::where('type', 'cycle')
                ->where('sequence', '<=', $currentKpi->sequence ?? 1)
                ->orderBy('sequence', 'asc')
                ->get();

            $kpiProgress = $allKpis->map(function($kpi) use ($customer, $approvedByCustomer, $currentKpiId, $getGoalsForCustomer) {
                // ⭐ Hitung assigned secara dinamis sesuai kategori customer
                $filteredGoals = $getGoalsForCustomer($kpi->id);
                $assigned = $filteredGoals->count();
                
                $approvedCount = $approvedByCustomer[$customer->id][$kpi->id] ?? 0;
                $percent = $assigned > 0 ? min(100, round(($approvedCount / $assigned) * 100, 2)) : 0;

                return [
                    'kpi_id' => $kpi->id,
                    'kpi_code' => $kpi->code,
                    'kpi_description' => $kpi->description,
                    'assigned_count' => (int) $assigned,
                    'approved_count' => (int) $approvedCount,
                    'percent' => (float) $percent,
                    'is_current' => $kpi->id === $currentKpiId,
                    'is_completed' => $percent >= 100,
                ];
            });

            // ⭐ Filter Daily Goals untuk Current KPI (Hanya yang sesuai kategori)
            $currentGoalsData = $getGoalsForCustomer($currentKpiId);
            
            $dailyGoals = $currentGoalsData->map(function($goal) use ($customer, $user) {
                $progress = Progress::where('daily_goal_id', $goal->id)
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $user->id)
                    ->whereNotNull('time_completed')
                    ->orderBy('created_at', 'desc')
                    ->with('attachments')
                    ->first();
                
                return [
                    'id' => $goal->id,
                    'description' => $goal->description,
                    'input_type' => $goal->input_type,
                    'evidence_required' => $goal->evidence_required,
                    'is_completed' => $progress && $progress->status === 'approved',
                    'is_rejected' => $progress && $progress->status === 'rejected',
                    'progress_id' => $progress ? $progress->id : null,
                    'progress_status' => $progress ? $progress->status : null,
                    'reviewer_note' => $progress ? $progress->reviewer_note : null,
                    'attachment' => ($progress && $progress->attachments->first()) 
                                    ? $progress->attachments->first()->file_path : null,
                ];
            })->values();

            // Stats Final
            $assignedNow = $currentGoalsData->count();
            $approvedNow = $approvedByCustomer[$customer->id][$currentKpiId] ?? 0;
            $percentNow = $assignedNow > 0 ? min(100, round(($approvedNow / $assignedNow) * 100, 2)) : 0;
            $actualPoint = ($percentNow / 100) * ($currentKpi->weight_point ?? 0);

            return [
                'customer' => [
                    'id' => $customer->id,
                    'pic' => $customer->pic,
                    'institution' => $customer->institution,
                    'status' => $customer->status,
                    'category' => $customer->category,
                ],
                'kpi' => $currentKpi ? $currentKpi->only(['id','code','description','weight_point']) : null,
                'kpi_progress_history' => $kpiProgress,
                'daily_goals' => $dailyGoals,
                'stats' => [
                    'assigned_count' => (int) $assignedNow,
                    'approved_count' => (int) $approvedNow,
                    'percent' => (float) $percentNow,
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
     * Get all daily goals for a given user and kpi.
     */
    public function byUserKpi(Request $request, $userId, $kpiId)
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($actor->role, ['administrator', 'sales_manager']) && $actor->id != (int)$userId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $dailyGoals = DailyGoal::where('user_id', $userId)
            ->where('kpi_id', $kpiId)
            ->get(['id','description','input_type','order','evidence_required']);

        return response()->json(['data' => $dailyGoals]);
    }

    public function update(Request $request, string $id)
    {
        // TODO: implement if needed
    }

    public function destroy(string $id)
    {
        // TODO: implement if needed
    }
}