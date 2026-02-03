<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $search = $request->get('search', '');
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            
            $query = Product::query();
            
            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }
            
            $products = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
            
            return response()->json([
                'data' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'has_more' => $products->hasMorePages()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memuat produk',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of products (for dropdown/selection).
     */
    public function list(): JsonResponse
    {
        try {
            $products = Product::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'default_price']);
            
            return response()->json($products);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memuat daftar produk',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'default_price' => 'required|integer|min:0',
                'specification' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            $product = Product::create([
                'name' => $validated['name'],
                'default_price' => $validated['default_price'],
                'specification' => $validated['specification'] ?? null,
                'is_active' => $validated['is_active'] ?? true
            ]);

            return response()->json([
                'message' => 'Produk berhasil ditambahkan',
                'data' => $product
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menambahkan produk',
                'errors' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            
            return response()->json([
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Produk tidak ditemukan',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'default_price' => 'required|integer|min:0',
                'specification' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            $product->update([
                'name' => $validated['name'],
                'default_price' => $validated['default_price'],
                'specification' => $validated['specification'] ?? null,
                'is_active' => $validated['is_active'] ?? true
            ]);

            return response()->json([
                'message' => 'Produk berhasil diperbarui',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memperbarui produk',
                'errors' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            
            // Check if product is being used by any customer
            if ($product->customers()->exists()) {
                return response()->json([
                    'message' => 'Produk tidak dapat dihapus karena sedang digunakan oleh customer'
                ], 422);
            }
            
            $product->delete();

            return response()->json([
                'message' => 'Produk berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menghapus produk',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle product active status.
     */
    public function toggleActive(string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            
            $product->update([
                'is_active' => !$product->is_active
            ]);

            return response()->json([
                'message' => $product->is_active ? 'Produk diaktifkan' : 'Produk dinonaktifkan',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengubah status produk',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

