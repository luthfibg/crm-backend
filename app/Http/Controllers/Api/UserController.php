<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;
use App\Models\CustomerKpiScore;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = UserResource::collection(User::all());
        return response()->json($users);
    }

    /**
     * Get user statistics (total points, customers, etc.)
     */
    public function getStats(Request $request, $userId)
    {
        $actor = $request->user();
        
        // Authorization: hanya bisa lihat stats sendiri kecuali admin
        if ($actor->role !== 'administrator' && $actor->id != $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::findOrFail($userId);

        // Hitung total points dari semua customers
        $totalPoints = CustomerKpiScore::where('user_id', $userId)
            ->sum('earned_points');

        // Hitung jumlah customers
        $totalCustomers = Customer::where('user_id', $userId)->count();
        
        $activeCustomers = Customer::where('user_id', $userId)
            ->whereIn('status', ['New', 'Warm Prospect', 'Hot Prospect'])
            ->count();

        // Update user points (optional, untuk sync)
        $user->points = (int) $totalPoints;
        $user->save();

        return response()->json([
            'totalPoints' => round($totalPoints, 2),
            'level' => $user->level,
            'totalCustomers' => $totalCustomers,
            'activeCustomers' => $activeCustomers,
            'badge' => $user->badge,
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
}
