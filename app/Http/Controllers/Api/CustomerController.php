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
            'category' => 'required|string', // Tambahkan validasi kategori
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

        // Mengambil data yang sudah tervalidasi
        $data = $validator->validated();
        
        $data['current_kpi_id'] = 1;
        $data['status'] = 'New';
        $data['status_changed_at'] = now();

        // Pastikan model Customer sudah menambahkan 'category' di $fillable
        $customer = Customer::create($data);

        $user = User::find($data['user_id']);
        if ($user) {
            $user->kpis()->syncWithoutDetaching([1]);
        }

        return response()->json(['customer' => $customer], 201);
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

    /**
     * Skip customer to next KPI (force advance)
     */
    public function skipKpi(Request $request, $customerId)
    {
        $actor = $request->user();
        $customer = Customer::findOrFail($customerId);

        // Authorization: hanya owner atau admin
        if ($actor->role !== 'administrator' && $customer->user_id !== $actor->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $currentKpiId = $customer->current_kpi_id;
        $currentKpi = KPI::find($currentKpiId);

        if (!$currentKpi) {
            return response()->json(['message' => 'Current KPI not found'], 404);
        }

        // Cari KPI berikutnya
        $nextKpi = KPI::where('type', 'cycle') // Hanya KPI cycle yang bisa di-skip
            ->where('sequence', '>', $currentKpi->sequence)
            ->orderBy('sequence', 'asc')
            ->first();

        if (!$nextKpi) {
            return response()->json(['message' => 'Sudah di KPI terakhir'], 400);
        }

        // Update customer
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

        \Log::info("Customer skipped to next KPI", [
            'customer_id' => $customer->id,
            'old_kpi' => $currentKpi->code,
            'new_kpi' => $nextKpi->code,
            'skipped_by' => $actor->id
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Berhasil skip ke KPI berikutnya',
            'new_kpi' => $nextKpi->description,
            'new_status' => $customer->status
        ]);
    }
}
