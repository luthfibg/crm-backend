<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\User;
use App\Models\KPI;
use App\Models\DailyGoal;
use App\Models\Product;
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

        // Allow dashboard access for all sales if ?dashboard=1
        if ($user->role !== 'administrator') {
            if ($request->query('dashboard') == 1) {
                // Allow access to all customers for dashboard
                if ($request->has('user_id') && $request->query('user_id') !== '') {
                    $query->where('user_id', $request->query('user_id'));
                }
                // else: all customers (for heatmap, etc)
            } else {
                // Default: only own customers
                $query->where('user_id', $user->id);
            }
        } else {
            // If admin provides a user_id filter, apply it
            if ($request->has('user_id') && $request->query('user_id') !== '') {
                $query->where('user_id', $request->query('user_id'));
            }
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
            'category' => 'required|string',
            'pic' => 'required|string|max:255',
            'institution' => 'required|string',
            'position' => 'nullable|string',
            'email' => 'nullable|email',
            'phone_number' => 'required',
            'notes' => 'nullable',
            'created_at' => 'nullable|date',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
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

        // Extract product_ids if present
        $productIds = $data['product_ids'] ?? [];
        unset($data['product_ids']);

        // Pastikan model Customer sudah menambahkan 'category' di $fillable
        $customer = Customer::create($data);

        $user = User::find($data['user_id']);
        if ($user) {
            $user->kpis()->syncWithoutDetaching([1]);
        }

        // Attach products if any
        if (!empty($productIds)) {
            $customer->products()->attach($productIds);
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
            'notes' => 'nullable',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validate();
        
        // Extract product_ids if present
        $productIds = $data['product_ids'] ?? [];
        unset($data['product_ids']);

        $customer = Customer::findOrFail($id);
        $customer->update($data);

        // Sync products if provided
        if ($productIds !== null) {
            $customer->products()->sync($productIds);
        }

        return response()->json($customer, 201);
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
     * Get customers that are not yet prospected (available to add as prospect)
     * Filters out customers with status: New, Warm Prospect, Hot Prospect
     */
    public function getAvailableForProspect(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Customer::query();

        // Only get customers belonging to the current user (unless admin)
        if ($user->role !== 'administrator') {
            $query->where('user_id', $user->id);
        }

        // Filter out customers that are already prospects
        // Prospects have status: New, Warm Prospect, Hot Prospect
        $prospectStatuses = ['New', 'Warm Prospect', 'Hot Prospect'];
        $query->whereNotIn('status', $prospectStatuses);

        // Also include customers with null status (never been prospected)
        $query->orWhere(function($q) use ($user, $prospectStatuses) {
            if ($user->role !== 'administrator') {
                $q->where('user_id', $user->id);
            }
            $q->whereNull('status');
        });

        $customers = $query->get();

        return CustomerResource::collection($customers);
    }

    /**
     * Convert existing customer to prospect (set to KPI 1 / New status)
     */
    public function convertToProspect(Request $request, $customerId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer = Customer::findOrFail($customerId);

        // Authorization: only owner or admin can convert
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if already a prospect
        $prospectStatuses = ['New', 'Warm Prospect', 'Hot Prospect'];
        if (in_array($customer->status, $prospectStatuses)) {
            return response()->json([
                'status' => false,
                'message' => 'Customer sudah menjadi prospek aktif'
            ], 400);
        }

        // Convert to prospect
        $customer->current_kpi_id = 1;
        $customer->kpi_id = 1;
        $customer->status = 'New';
        $customer->status_changed_at = now();
        $customer->save();

        // Ensure user has KPI 1 attached
        $user->kpis()->syncWithoutDetaching([1]);

        \Log::info("Customer converted to prospect", [
            'customer_id' => $customer->id,
            'converted_by' => $user->id
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Customer berhasil ditambahkan ke pipeline',
            'customer' => new CustomerResource($customer)
        ], 200);
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

    /**
     * Get completed sales history (customers with After Sales status)
     */
    public function getSalesHistory(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Customer::with(['user', 'kpi'])
            ->where('status', 'Completed');

        // Filter by user if not admin
        if ($user->role !== 'administrator') {
            $query->where('user_id', $user->id);
        }

        // Order by completion date (when status changed to After Sales)
        $query->orderBy('status_changed_at', 'desc');

        $perPage = (int) $request->query('per_page', 15);
        $sales = $query->paginate(max(1, $perPage));

        // Transform data for frontend
        $salesData = $sales->getCollection()->map(function($customer) {
            return [
                'id' => $customer->id,
                'pic' => $customer->pic,
                'institution' => $customer->institution,
                'category' => $customer->category,
                'sales_person' => $customer->user ? $customer->user->name : 'Unknown',
                'completed_at' => $customer->status_changed_at,
                'final_kpi' => $customer->kpi ? $customer->kpi->description : 'Unknown',
                'notes' => $customer->notes
            ];
        });

        return response()->json([
            'data' => $salesData,
            'meta' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'total' => $sales->total(),
                'per_page' => $sales->perPage()
            ]
        ]);
    }

    /**
     * Get products for a specific customer.
     */
    public function getProducts(Request $request, $customerId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        // Authorization: only owner or admin can view
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $products = $customer->products()->get();

        return response()->json([
            'customer_id' => $customerId,
            'products' => $products
        ]);
    }

    /**
     * Attach a product to a customer.
     */
    public function attachProduct(Request $request, $customerId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validator = Validator::make(array_merge(['customer_id' => $customerId], $request->all()), [
            'customer_id' => 'required|exists:customers,id',
            'product_id' => 'required|exists:products,id',
            'negotiated_price' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $customer = Customer::findOrFail($customerId);

        // Authorization: only owner or admin can attach
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $validator->validated();

        // Sync with additional pivot data
        $pivotData = [
            'negotiated_price' => $validated['negotiated_price'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        $customer->products()->syncWithoutDetaching([
            $validated['product_id'] => $pivotData
        ]);

        $product = Product::find($validated['product_id']);

        return response()->json([
            'status' => true,
            'message' => 'Product berhasil ditambahkan ke customer',
            'product' => $product,
            'pivot' => $pivotData
        ]);
    }

    /**
     * Detach a product from a customer.
     */
    public function detachProduct(Request $request, $customerId, $productId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer = Customer::findOrFail($customerId);

        // Authorization: only owner or admin can detach
        if ($user->role !== 'administrator' && $customer->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $detached = $customer->products()->detach($productId);

        if ($detached) {
            return response()->json([
                'status' => true,
                'message' => 'Product berhasil dihapus dari customer'
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Product tidak ditemukan pada customer ini'
        ], 404);
    }
}
