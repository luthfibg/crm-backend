<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\User;
use App\Models\KPI;
use App\Models\DailyGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator; 

class CustomerController extends Controller
{
    /**
     * Display a listing of the customers. Only administrator could displays all customers. Meanwhile sales could only displays customers their own.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Customer::query();

        // Administrator can view all customers; other roles only their own
        if ($user->role !== 'administrator') {
            $query->where('user_id', $user->id);
        }

        // Use pagination for lists (per_page query param, defaults to 15)
        $perPage = (int) $request->query('per_page', 15);
        $customers = $query->paginate(max(1, $perPage));

        return CustomerResource::collection($customers);
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'kpi_id' => 'required|integer',
            'pic' => 'required|string|max:255',
            'institution' => 'required|string',
            'position' => 'nullable|string',
            'email' => 'nullable|email',
            'phone_number' => 'required',
            'notes' => 'nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validate();

        // Set initial CRM status and current KPI pointer for new customer
        $data['current_kpi_id'] = 1;
        $data['status'] = 'New';
        $data['status_changed_at'] = now();

        $customer = Customer::create($data);

        // Attach KPI id 1 to the user (if not already attached) so the sales has the KPI active
        $user = User::find($data['user_id']);
        $createdDailyGoal = null;
        if ($user) {
            $kpi1 = KPI::find(1);
            if ($kpi1) {
                $user->kpis()->syncWithoutDetaching([1]);

                // Create an auto-generated daily goal for KPI #1 assigned to the sales
                $createdDailyGoal = DailyGoal::create([
                    'description' => "Auto-generated goal for KPI {$kpi1->code} - customer {$customer->name}",
                    'user_id' => $user->id,
                    'kpi_id' => $kpi1->id,
                    'is_completed' => false,
                ]);
            }
        }

        $response = ['customer' => $customer];
        if ($createdDailyGoal) {
            $response['daily_goal'] = $createdDailyGoal;
        }

        return response()->json($response, 201);
    }

    /**
     * Display the specified customer.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer = Customer::find($id);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        // Only administrators or the owner (sales) can view the customer
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return new CustomerResource($customer);
    }

    /**
     * Update the specified customer in storage.
     */
    public function update(Request $request, string $id)
    {
         $validator = Validator::make($request->all(), [
            'pic' => 'required|string|max:255',
            'institution' => 'required|string',
            'position' => 'nullable|string',
            'email' => 'nullable|email',
            'phone_number' => 'required',
            'notes' => 'nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validate();
        $customer = Customer::findOrFail($id);
        $customer->update($data);
        return response()->json($customer,201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();
        return response()->json(null, 204);
    }
}
