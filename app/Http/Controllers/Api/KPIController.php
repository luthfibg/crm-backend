<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KPI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KPIController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $kpis = KPI::all();
        return response()->json($kpis);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:100|unique:kpis,code',
            'description' => 'required|string',
            'weight_point' => 'required|integer|max:50',
            'type' => 'required|in:cycle,periodic,achievement'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        $kpi = KPI::create($data);
        return response()->json($kpi, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $kpi = KPI::findOrFail($id);
        return response()->json($kpi);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:100|unique:kpis,code',
            'description' => 'required|string',
            'weight_point' => 'required|integer|max:50',
            'type' => 'required|in:cycle,periodic,achievement'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $kpi = KPI::findOrFail($id);
        $kpi->update($data);

        return response()->json($kpi, 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $kpi = KPI::findOrFail($id);
        $kpi->delete();
        return response()->json(null, 204);
    }
}
