<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Product::query();

        // Search by name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Admin can see all, sales only see active
        if ($user->role !== 'administrator') {
            $query->where('is_active', true);
        }

        $perPage = (int) $request->query('per_page', 15);
        $products = $query->orderBy('name')->paginate(max(1, $perPage));

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
                'per_page' => $products->perPage()
            ]
        ]);
    }

    /**
     * Display a simple list of products for dropdowns (no pagination).
     */
    public function list(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $products = Product::active()->orderBy('name')->get(['id', 'name', 'default_price', 'description']);

        return response()->json($products);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:products,name',
            'default_price' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = $user->id;

        $product = Product::create($data);

        return response()->json([
            'status' => true,
            'message' => 'Product created successfully',
            'product' => $product
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $product = Product::with('creator')->find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Non-admin can only see active products
        if ($user->role !== 'administrator' && !$product->is_active) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json($product);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:products,name,' . $id,
            'default_price' => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $product->update($validator->validated());

        return response()->json([
            'status' => true,
            'message' => 'Product updated successfully',
            'product' => $product
        ]);
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only admin can delete products
        if ($user->role !== 'administrator') {
            return response()->json(['message' => 'Forbidden. Only administrators can delete products.'], 403);
        }

        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Check if product is being used by any customer
        if ($product->customers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete product. Product is being used by customers. Consider setting is_active to false instead.'
            ], 400);
        }

        $product->delete();

        return response()->json([
            'status' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Toggle product active status.
     */
    public function toggleActive(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only admin can toggle active status
        if ($user->role !== 'administrator') {
            return response()->json(['message' => 'Forbidden. Only administrators can toggle product status.'], 403);
        }

        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product->is_active = !$product->is_active;
        $product->save();

        return response()->json([
            'status' => true,
            'message' => $product->is_active ? 'Product activated' : 'Product deactivated',
            'product' => $product
        ]);
    }
}

