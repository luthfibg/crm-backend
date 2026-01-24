<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;
use App\Models\CustomerSummary;
use App\Models\User;
use App\Models\DailyGoal;
use App\Models\Progress;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    /**
     * Export progress report per prospect
     * Query params:
     * - format: pdf|excel|word (default: pdf)
     * - range: daily|monthly (default: monthly)
     * - date: YYYY-MM-DD (required when range=daily)
     * - month: YYYY-MM (required when range=monthly)
     * - user_id: optional (filter sales)
     */
    public function progressReport(Request $request)
    {
        $actor = $request->user();
        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);

        $validator = Validator::make($request->all(), [
            'format' => 'sometimes|string|in:pdf,excel,word',
            'range' => 'sometimes|string|in:daily,monthly',
            'date' => 'sometimes|date_format:Y-m-d',
            'month' => 'sometimes|date_format:Y-m',
            'user_id' => 'sometimes|integer|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $format = $request->get('format', 'pdf');
        $range = $request->get('range', 'monthly');
        $date = $request->get('date');
        $month = $request->get('month');
        $filterUserId = $request->get('user_id');

        // Determine date range
        if ($range === 'daily') {
            $start = $date ? \Carbon\Carbon::createFromFormat('Y-m-d', $date)->startOfDay() : now()->startOfDay();
            $end = (clone $start)->endOfDay();
            $label = $start->toDateString();
        } else {
            $start = $month ? \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth() : now()->startOfMonth();
            $end = (clone $start)->endOfMonth();
            $label = $start->format('Y-m');
        }

        // Scope customers
        if ($actor->role === 'administrator') {
            $customers = Customer::with(['user', 'kpi', 'summary'])
                ->with(['progresses' => function($q) use ($start, $end) {
                    $q->whereBetween('time_completed', [$start, $end]);
                }])
                ->get();
        } else {
            $customers = $actor->customers()
                ->with(['user', 'kpi', 'summary'])
                ->with(['progresses' => function($q) use ($start, $end) {
                    $q->whereBetween('time_completed', [$start, $end]);
                }])
                ->get();
        }

        if ($filterUserId) {
            $customers = $customers->filter(fn($c) => $c->user_id == $filterUserId)->values();
        }

        // Build report rows with new columns
        $rows = $customers->map(function($customer, $index) use ($start, $end) {
            $currentKpiId = $customer->current_kpi_id ?? $customer->kpi_id;

            // Total assigned daily goals for the sales and KPI
            $totalAssigned = DailyGoal::where('user_id', $customer->user_id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->count();

            // Approved in period
            $approvedInPeriod = Progress::where('customer_id', $customer->id)
                ->where('user_id', $customer->user_id)
                ->where('status', 'approved')
                ->whereBetween('time_completed', [$start, $end])
                ->distinct('daily_goal_id')
                ->count('daily_goal_id');

            // KPI current overall approved
            $totalApprovedOverall = Progress::where('customer_id', $customer->id)
                ->where('user_id', $customer->user_id)
                ->where('status', 'approved')
                ->where('kpi_id', $currentKpiId)
                ->distinct('daily_goal_id')
                ->count('daily_goal_id');

            $kpiPercentPeriod = $totalAssigned ? round(($approvedInPeriod / $totalAssigned) * 100, 2) : 0;
            $kpiPercentOverall = $totalAssigned ? round(($totalApprovedOverall / $totalAssigned) * 100, 2) : 0;

            // Submission dates in period
            $firstSubmission = Progress::where('customer_id', $customer->id)
                ->where('user_id', $customer->user_id)
                ->whereBetween('time_completed', [$start, $end])
                ->orderBy('time_completed', 'asc')
                ->value('time_completed');

            $lastSubmission = Progress::where('customer_id', $customer->id)
                ->where('user_id', $customer->user_id)
                ->whereBetween('time_completed', [$start, $end])
                ->orderBy('time_completed', 'desc')
                ->value('time_completed');

            // Per daily goal details (last submission within period)
            $dailyDetails = DailyGoal::where('user_id', $customer->user_id)
                ->where('kpi_id', $currentKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->get(['id','description'])
                ->map(function($dg) use ($customer, $start, $end) {
                    $p = Progress::where('daily_goal_id', $dg->id)
                        ->where('customer_id', $customer->id)
                        ->whereBetween('time_completed', [$start, $end])
                        ->orderBy('created_at', 'desc')
                        ->first();

                    return [
                        'daily_goal' => $dg->description,
                        'status' => $p ? $p->status : 'pending',
                        'last_submitted_at' => $p ? $p->time_completed : null,
                        'reviewer_note' => $p ? $p->reviewer_note : null,
                    ];
                });

            // Get summary for this customer (current KPI)
            $summary = $customer->summary;
            $kesimpulan = $summary ? $summary->summary : '-';

            return [
                'no' => $index + 1,
                'sales_name' => $customer->user ? $customer->user->name : '-',
                'customer_name' => $customer->pic,
                'institution' => $customer->institution,
                'product' => '-', // Reserved for future
                'status' => $customer->status ?? '-',
                'kesimpulan' => $kesimpulan,
                'harga_penawaran' => '-', // Reserved for future
                'harga_deal' => '-', // Reserved for future
                'kpi_progress' => $kpiPercentOverall,
                'position' => $customer->position,
                'total_assigned' => $totalAssigned,
                'approved_in_period' => $approvedInPeriod,
                'kpi_percent_period' => $kpiPercentPeriod,
                'first_submission' => $firstSubmission,
                'last_submission' => $lastSubmission,
                'daily_details' => $dailyDetails,
            ];
        });

        // Render according to format
        if ($format === 'excel') {
            // Return CSV stream (simple & no extra dependency)
            $filename = "progress_report_{$label}.csv";
            $callback = function() use ($rows) {
                $out = fopen('php://output', 'w');
                // Header with new columns
                fputcsv($out, [
                    'No', 
                    'Sales', 
                    'Customer', 
                    'Institution', 
                    'Product', 
                    'Status', 
                    'Kesimpulan', 
                    'Harga Penawaran', 
                    'Harga Deal', 
                    'KPI Progress %'
                ]);
                foreach ($rows as $r) {
                    // Escape kesimpulan for CSV (handle commas, quotes, newlines)
                    $kesimpulan = str_replace(["\r\n", "\n", "\r"], ' ', $r['kesimpulan']);
                    $kesimpulan = str_replace('"', '""', $kesimpulan);
                    
                    fputcsv($out, [
                        $r['no'],
                        $r['sales_name'],
                        $r['customer_name'],
                        $r['institution'],
                        $r['product'],
                        $r['status'],
                        '"' . $kesimpulan . '"',
                        $r['harga_penawaran'],
                        $r['harga_deal'],
                        $r['kpi_progress'] . '%'
                    ]);
                }
                fclose($out);
            };

            return Response::stream($callback, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename={$filename}",
            ]);
        }

        // For PDF or Word, render Blade view
        $view = View::make('reports.progress', [
            'label' => $label,
            'range' => $range,
            'start' => $start,
            'end' => $end,
            'rows' => $rows,
        ]);

        if ($format === 'word') {
            $filename = "progress_report_{$label}.doc";
            $html = $view->render();
            return response($html, 200, [
                'Content-Type' => 'application/msword',
                'Content-Disposition' => "attachment; filename={$filename}"
            ]);
        }

        // PDF (uses barryvdh/laravel-dompdf if available)
        if (class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            try {
                $pdf = Pdf::loadView('reports.progress', [
                    'label' => $label,
                    'range' => $range,
                    'start' => $start,
                    'end' => $end,
                    'rows' => $rows,
                ]);

                return $pdf->download("progress_report_{$label}.pdf");
            } catch (\Exception $e) {
                Log::error('PDF generation error: ' . $e->getMessage());
                return response()->json(['status' => false, 'message' => 'Gagal membuat PDF. Cek log.'], 500);
            }
        }

        // Fallback: return HTML view
        return response($view->render(), 200, ['Content-Type' => 'text/html']);
    }
}
