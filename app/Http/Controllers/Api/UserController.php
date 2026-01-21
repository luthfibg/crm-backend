<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;
use App\Models\CustomerKpiScore;
use App\Models\Progress;
use App\Models\KPI;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     * Only returns users with 'sales' role for SalesWorkspace chart and SalesPersonCard
     */
    public function index(Request $request)
    {
        // Filter to only show sales users in the sales workspace
        $users = UserResource::collection(User::where('role', 'sales')->get());
        return response()->json($users);
    }

    /**
     * Get user statistics (total points, customers, etc.)
     */
    public function getStats(Request $request, $userId)
    {
        // Restrict getting stats to the user themselves or administrators
        /*
        $actor = $request->user();
        if ($actor->role !== 'administrator' && $actor->id != $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        */

        $user = User::findOrFail($userId);

        // Hitung total points
        $totalPoints = CustomerKpiScore::where('user_id', $userId)->sum('earned_points');

        // Hitung jumlah customers
        $totalCustomers = Customer::where('user_id', $userId)->count();
        
        $activeCustomers = Customer::where('user_id', $userId)
            ->whereIn('status', ['New', 'Warm Prospect', 'Hot Prospect'])
            ->count();

        // Opsional: Tetap update points (pastikan kolom ini ada di table users)
        $user->points = (int) $totalPoints;
        $user->save();

        return response()->json([
            'totalPoints' => round($totalPoints, 2),
            'level' => $user->level,
            'totalCustomers' => $totalCustomers,
            'activeCustomers' => $activeCustomers,
            'badge' => $user->badge,
            // Pastikan field di bawah ini ada agar Frontend tidak bernilai 0
            'new_prospects' => $activeCustomers, 
            'hot_prospects' => Customer::where('user_id', $userId)->where('status', 'Hot Prospect')->count(),
            'closed_deals' => Customer::where('user_id', $userId)->where('status', 'Closed')->count(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'role' => 'in:administrator,sales,presales,telesales',
            'points' => 'nullable|integer',
            'level' => 'nullable|integer',
            'bio' => 'nullable|string|max:500',
            'badge' => 'nullable|string|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);
        return response()->json(new UserResource($user), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);
        return response()->json(new UserResource($user));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'points' => 'nullable|integer',
            'level' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:500',
            'badge' => 'nullable|string|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        $data['password'] = Hash::make($data['password']);

        $user = User::findOrFail($id);
        $user->update($data);
        return response()->json(new UserResource($user), 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Update user settings such as is_developer_mode and allow_force_push
     */
    public function updateSettings(Request $request)
    {
        $user = $request->user();
        
        // Validasi input agar hanya kolom tertentu yang bisa diupdate via endpoint ini
        $data = $request->validate([
            'is_developer_mode' => 'sometimes|boolean',
            'allow_force_push'  => 'sometimes|boolean',
            'status' => 'sometimes|in:New, Warm Prospect, Hot Prospect, Customer, Inactive'
        ]);

        // Convert boolean to integer for database
        if (isset($data['is_developer_mode'])) {
            $data['is_developer_mode'] = $data['is_developer_mode'] ? 1 : 0;
            Log::info("Updating developer_mode to: " . $data['is_developer_mode']);
        }
        
        if (isset($data['allow_force_push'])) {
            $data['allow_force_push'] = $data['allow_force_push'] ? 1 : 0;
            Log::info("Updating allow_force_push to: " . $data['allow_force_push']);
        }

        // Hanya izinkan administrator untuk mengubah setting ini jika perlu
        if ($user->role !== 'administrator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user->update($data);
        
        // Log perubahan
        Log::info("User {$user->id} settings updated: " . json_encode($data));

        return response()->json([
            'status' => true,
            'message' => 'Settings updated successfully',
            'user' => $user->fresh() // Return fresh data from database
        ]);
    }

    /**
     * Get sales persons with their pipelines for dashboard
     */
    public function getSalesWithPipelines(Request $request)
    {
        $actor = $request->user();
        if (!$actor || $actor->role !== 'administrator') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $salesUsers = User::where('role', 'sales')->get();

        $result = $salesUsers->map(function($user) {
            // Get active customers for this user
            $customers = Customer::where('user_id', $user->id)
                ->whereIn('status', ['New', 'Warm Prospect', 'Hot Prospect'])
                ->with('kpi')
                ->get();

            // Calculate total score
            $totalScore = CustomerKpiScore::where('user_id', $user->id)->sum('earned_points');

            // Build pipelines
            $pipelines = $customers->map(function($customer) {
                // Map KPI sequence to stage (1-5)
                $sequence = $customer->kpi ? $customer->kpi->sequence : 1;
                $stage = min($sequence, 5); // Max stage 5

                // Get last progress date
                $lastProgress = Progress::where('customer_id', $customer->id)
                    ->where('user_id', $customer->user_id)
                    ->orderBy('time_completed', 'desc')
                    ->first();

                return [
                    'pic' => $customer->pic,
                    'title' => $customer->position ?? 'Position',
                    'company' => $customer->institution,
                    'stage' => $stage,
                    'date' => $lastProgress ? $lastProgress->time_completed->format('M d, Y') : 'No activity'
                ];
            })->toArray();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar ?? 'https://i.pravatar.cc/150?u=' . $user->id,
                'summary' => $user->bio ?? 'Sales Representative',
                'pipelines' => $pipelines,
                'totalScore' => $totalScore
            ];
        });

        return response()->json(['data' => $result]);
    }
}
