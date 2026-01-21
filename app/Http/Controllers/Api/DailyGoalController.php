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

    // Ambil customer dengan relasi KPI saat ini
    $query = \App\Models\Customer::with('kpi')
        ->whereIn('status', ['New', 'Warm Prospect', 'Hot Prospect']);
    $customers = ($user->role === 'administrator') ? $query->get() : $user->customers()->with('kpi')->whereIn('status', ['New', 'Warm Prospect', 'Hot Prospect'])->get();

    // 1. Ambil Master Daily Goals (Gunakan WHERE IN kpi_id agar lebih cepat)
    $allDailyGoals = DailyGoal::where('user_id', $user->id)
        ->where('description', 'NOT LIKE', 'Auto-generated%')
        ->get();

    // 2. Mapping Group (Pastikan konsisten dengan inputan Admin)
    $groupMapping = [
        'UKPBJ'            => 'KEDINASAN',
        'RUMAH SAKIT'      => 'KEDINASAN',
        'KANTOR KEDINASAN' => 'KEDINASAN',
        'KANTOR BALAI'     => 'KEDINASAN',
        'KELURAHAN'        => 'KECAMATAN',
        'KECAMATAN'        => 'KECAMATAN',
        'PUSKESMAS'        => 'PUSKESMAS'
    ];

    // Mapping category ke daily_goal_type_id
    $categoryToTypeMapping = [
        'Pendidikan' => 1,
        'Pemerintahan' => 2,
        'Web Inquiry Corporate' => 3,
        'Web Inquiry CNI' => 4,
        'Web Inquiry C&I' => 4, // Handle typo
    ];

    // 3. Ambil data PROGRESS yang sudah APPROVED
    $approvedByCustomer = Progress::where('user_id', $user->id)
        ->where('status', 'approved')
        ->whereNotNull('time_completed')
        ->whereIn('customer_id', $customers->pluck('id'))
        ->get()
        ->groupBy('customer_id')
        ->map(function($items) {
            return $items->groupBy('kpi_id')->map(fn($group) => $group->count());
        });

    // 4. Ambil last follow-up time per customer (waktu terakhir input daily goal)
    $lastFollowUpByCustomer = Progress::where('user_id', $user->id)
        ->whereNotNull('time_completed')
        ->whereIn('customer_id', $customers->pluck('id'))
        ->orderBy('time_completed', 'desc')
        ->get()
        ->groupBy('customer_id')
        ->map(function($items) {
            return $items->first()->time_completed;
        });

    $result = $customers->map(function($customer) use ($user, $allDailyGoals, $approvedByCustomer, $lastFollowUpByCustomer, $groupMapping, $categoryToTypeMapping) {
        $currentKpi = $customer->kpi;
        $currentKpiId = $customer->current_kpi_id;
        
        // Mapping Sub-Kategori
        $rawSub = strtoupper($customer->sub_category ?? '');
        $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;

        // Fungsi Filter Goals yang lebih tangguh
        $getGoalsForCustomer = function($kpiId) use ($allDailyGoals, $customer, $targetGoalGroup, $categoryToTypeMapping) {
            return $allDailyGoals->filter(function($goal) use ($kpiId, $customer, $targetGoalGroup, $categoryToTypeMapping) {
                // Syarat 1: KPI ID harus cocok
                if ($goal->kpi_id != $kpiId) return false;

                // Syarat 2: Jika Pemerintahan, cek sub_category mapping
                if (strtolower($customer->category ?? '') === 'pemerintahan') {
                    // Jika goal tidak punya sub_category, skip filter sub_category
                    if (empty($goal->sub_category)) return true;
                    return strtoupper($goal->sub_category) === strtoupper($targetGoalGroup);
                }

                // Syarat 3: Untuk kategori lain, cocokkan daily_goal_type_id berdasarkan category
                $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;
                
                // Jika expectedTypeId null (kategori tidak dikenal) atau goal tidak punya type_id,
                // fallback: tampilkan semua goals untuk KPI tersebut
                if ($expectedTypeId === null || $goal->daily_goal_type_id === null) {
                    return true;
                }
                
                return $goal->daily_goal_type_id == $expectedTypeId;
            });
        };

        // History KPI (Pastikan sequence diambil dari KPI master)
        $allKpis = \App\Models\KPI::where('type', 'cycle')
            ->where('sequence', '<=', ($currentKpi->sequence ?? 10)) // Ambil sampai tahap skrg atau lebih
            ->orderBy('sequence', 'asc')
            ->get();

        $kpiProgress = $allKpis->map(function($kpi) use ($customer, $approvedByCustomer, $currentKpiId, $getGoalsForCustomer) {
            $filteredGoals = $getGoalsForCustomer($kpi->id);
            $assigned = $filteredGoals->count();
            $approvedCount = $approvedByCustomer[$customer->id][$kpi->id] ?? 0;
            
            // Logic: Jika assigned 0 tapi approved ada (data lama), tetap anggap selesai
            // Jika assigned > 0, hitung persentase normal
            if ($assigned > 0) {
                $percent = min(100, round(($approvedCount / $assigned) * 100, 2));
            } else {
                $percent = $approvedCount > 0 ? 100 : 0;
            }

            return [
                'kpi_id'          => $kpi->id,
                'kpi_description' => $kpi->description,
                'kpi_weight'      => $kpi->weight_point,
                'assigned_count'  => (int) $assigned,
                'approved_count'  => (int) $approvedCount,
                'percent'         => (float) $percent,
                'is_current'      => $kpi->id == $currentKpiId,
                'is_completed'    => $percent >= 100,
            ];
        });

        // Daily Goals untuk KPI Aktif
        $currentGoalsData = $getGoalsForCustomer($currentKpiId);
        $dailyGoals = $currentGoalsData->map(function($goal) use ($customer, $user) {
            $progress = Progress::where('daily_goal_id', $goal->id)
                ->where('customer_id', $customer->id)
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            return [
                'id'                => $goal->id,
                'description'       => $goal->description,
                'input_type'        => $goal->input_type,
                'is_completed'      => $progress && $progress->status === 'approved',
                'is_rejected'       => $progress && $progress->status === 'rejected',
                'progress_id'       => $progress ? $progress->id : null,
                'progress_status'   => $progress ? $progress->status : null,
            ];
        })->values();

        // Statistik Current KPI
        $statsCurrent = $kpiProgress->firstWhere('kpi_id', $currentKpiId) ?? ['percent' => 0, 'approved_count' => 0, 'assigned_count' => 0];

        return [
            'customer' => [
                'id'           => $customer->id,
                'pic'          => $customer->pic,
                'institution'  => $customer->institution,
                'category'     => $customer->category,
                'sub_category' => $customer->sub_category,
                'status'       => $customer->status,
            ],
            'kpi' => $currentKpi ? $currentKpi->only(['id','code','description','weight_point']) : null,
            'kpi_progress_history' => $kpiProgress,
            'daily_goals' => $dailyGoals,
            'stats' => [
                'percent'        => (float) $statsCurrent['percent'],
                'approved_count' => (int) $statsCurrent['approved_count'],
                'assigned_count' => (int) $statsCurrent['assigned_count'],
            ],
            'last_followup_at' => $lastFollowUpByCustomer[$customer->id] ?? null,
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
        if (!$actor) return response()->json(['message' => 'Unauthenticated.'], 401);

        if (!in_array($actor->role, ['administrator', 'sales_manager'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'kpi_id'  => 'required|integer|exists:kpis,id',
            // Tambahkan ID Tipe/Kategori Utama
            'daily_goal_type_id' => 'required|integer|exists:daily_goal_types,id',
            // Sub-Kategori wajib diisi HANYA JIKA tipe yang dipilih adalah Pemerintahan
            'sub_category' => 'nullable|string|in:Kedinasan,Kecamatan,Puskesmas', 
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
        $kpi  = KPI::findOrFail($data['kpi_id']);

        DB::beginTransaction();
        try {
            $user->kpis()->syncWithoutDetaching([$kpi->id]);

            $created = [];
            foreach ($data['daily_goals'] as $dg) {
                $created[] = DailyGoal::create([
                    'user_id' => $user->id,
                    'kpi_id'  => $kpi->id,
                    'daily_goal_type_id' => $data['daily_goal_type_id'], // Simpan kategori utama
                    'sub_category'       => $data['sub_category'] ?? null, // Simpan sub-kategori (jika ada)
                    'description'        => $dg['description'],
                    'is_completed'       => $dg['is_completed'] ?? false,
                    'input_type'         => $dg['input_type'] ?? 'none',
                    'order'              => $dg['order'] ?? null,
                    'evidence_required'  => $dg['evidence_required'] ?? false,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Daily goals created successfully',
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