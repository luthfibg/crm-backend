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
use App\Models\Product;
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

        // Scope customers with different filters based on range
        // Daily: filter by progresses.time_completed (when daily goals were submitted)
        // Monthly: filter by status_changed_at (when prospect status changed)
        if ($actor->role === 'administrator') {
            $customersQuery = Customer::with(['user', 'kpi', 'summaries'])
                ->with(['products']);
        } else {
            $customersQuery = $actor->customers()
                ->with(['user', 'kpi', 'summaries'])
                ->with(['products']);
        }

        if ($range === 'daily') {
            // Daily: filter customers that have at least one progress submission on that day
            $customersQuery->whereHas('progresses', function($q) use ($start, $end) {
                $q->whereBetween('time_completed', [$start, $end]);
            });
        } else {
            // Monthly: filter customers by status_changed_at
            $customersQuery->whereBetween('status_changed_at', [$start, $end]);
        }

        $customers = $customersQuery->get();

        // Load progresses for the period
        $customers = $customers->map(function($customer) use ($start, $end) {
            $customer->load(['progresses' => function($q) use ($start, $end) {
                $q->whereBetween('time_completed', [$start, $end]);
            }]);
            return $customer;
        });

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
            $summary = $customer->summaries->first();

            // Get last follow-up time (last time_completed from progresses)
            $lastFollowUpRaw = Progress::where('customer_id', $customer->id)
                ->where('user_id', $customer->user_id)
                ->whereNotNull('time_completed')
                ->orderBy('time_completed', 'desc')
                ->value('time_completed');

            // Convert to Carbon instance if it's a string, otherwise use customer created_at as fallback
            if ($lastFollowUpRaw) {
                $lastFollowUp = \Carbon\Carbon::parse($lastFollowUpRaw);
            } else {
                // Fallback to customer created_at if no progress entries
                $lastFollowUp = $customer->created_at ? \Carbon\Carbon::parse($customer->created_at) : null;
            }

            // Format last follow-up date
            $lastFollowUpText = '';
            if ($lastFollowUp) {
                // Format: "Senin, 01 Februari 2026"
                $lastFollowUpText = $lastFollowUp->locale('id_ID')->format('l, d F Y');
            }

            // If no summary exists, show message about incomplete mandatory goals
            if ($summary) {
                $kesimpulan = $summary->summary;
            } else {
                $kesimpulan = "Target mandatory yang sekarang ini masih belum diselesaikan" .
                    ($lastFollowUpText ? "\n(Data ini terakhir diupdate {$lastFollowUpText})" : '');
            }

            // Get After Sales daily goal inputs (kpi_id = 6)
            $afterSalesKpiId = 6;
            $afterSalesDailyGoals = DailyGoal::where('kpi_id', $afterSalesKpiId)
                ->where('description', 'NOT LIKE', 'Auto-generated%')
                ->get(['id', 'description']);

            // Map after sales daily goals to columns
            $jadwalKunjunganPresales = '';
            $garansiUnit = '';
            $serialNumberUnit = '';

            foreach ($afterSalesDailyGoals as $dg) {
                $progress = Progress::where('daily_goal_id', $dg->id)
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $customer->user_id)
                    ->where('status', 'approved')
                    ->with(['attachments']) // Load attachments to get user input from content
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($progress) {
                    // Get user input from attachment's content field
                    $userInput = '';
                    if ($progress->attachments && $progress->attachments->isNotEmpty()) {
                        // Get content from the first attachment that has content
                        $attachment = $progress->attachments->firstWhere('content', '!=', '');
                        $userInput = $attachment ? $attachment->content : '';
                    }

                    if ($userInput) {
                        // Check description to determine which column it belongs to
                        $desc = strtolower($dg->description);
                        if (strpos($desc, 'jadwal kunjungan presales') !== false || strpos($desc, 'jadwal kunjungan') !== false) {
                            $jadwalKunjunganPresales = $userInput;
                        } elseif (strpos($desc, 'garansi') !== false) {
                            $garansiUnit = $userInput;
                        } elseif (strpos($desc, 'serial number') !== false || strpos($desc, 'sn') !== false) {
                            $serialNumberUnit = $userInput;
                        }
                    }
                }
            }

            // Get product names (comma-separated)
            $products = $customer->products;
            $productNames = $products->isNotEmpty() 
                ? $products->pluck('name')->implode(', ')
                : '-';
            
            // Calculate total deal value from negotiated prices or default prices
            $totalDealValue = $products->sum(function($product) {
                $pivot = $product->pivot;
                return $pivot && $pivot->negotiated_price 
                    ? $pivot->negotiated_price 
                    : $product->default_price;
            });

            return [
                'no' => $index + 1,
                'sales_name' => $customer->user ? $customer->user->name : '-',
                'customer_name' => $customer->pic,
                'institution' => $customer->institution,
                'product' => $productNames,
                'status' => $customer->status ?? '-',
                'kesimpulan' => $kesimpulan,
                'harga_penawaran' => $products->isNotEmpty() ? 'Rp ' . number_format($products->sum('default_price'), 0, ',', '.') : '-',
                'harga_deal' => $products->isNotEmpty() ? 'Rp ' . number_format($totalDealValue, 0, ',', '.') : '-',
                'kpi_progress' => $kpiPercentOverall,
                'jadwal_kunjungan_presales' => $jadwalKunjunganPresales,
                'garansi_unit' => $garansiUnit,
                'serial_number_unit' => $serialNumberUnit,
                'position' => $customer->position,
                'total_assigned' => $totalAssigned,
                'approved_in_period' => $approvedInPeriod,
                'kpi_percent_period' => $kpiPercentPeriod,
                'first_submission' => $firstSubmission,
                'last_submission' => $lastSubmission,
                'daily_details' => $dailyDetails,
                'approved_daily_goals_count' => $totalApprovedOverall,
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
                    'Keterangan Status',
                    'Kesimpulan',
                    'Harga Penawaran',
                    'Harga Deal',
                    'Jadwal Kunjungan Presales',
                    'Garansi Unit/Barang',
                    'Serial Number Unit/Barang'
                ]);
                foreach ($rows as $r) {
                    // Escape kesimpulan for CSV (handle commas, quotes, newlines)
                    $kesimpulan = str_replace(["\r\n", "\n", "\r"], ' ', $r['kesimpulan']);
                    $kesimpulan = str_replace('"', '""', $kesimpulan);

                    // Keterangan Status: "{count} mandatory selesai"
                    $keteranganStatus = $r['approved_daily_goals_count'] . ' mandatory selesai';

                    fputcsv($out, [
                        $r['no'],
                        $r['sales_name'],
                        $r['customer_name'],
                        $r['institution'],
                        $r['product'],
                        $r['status'],
                        $keteranganStatus,
                        '"' . $kesimpulan . '"',
                        $r['harga_penawaran'],
                        $r['harga_deal'],
                        $r['jadwal_kunjungan_presales'],
                        $r['garansi_unit'],
                        $r['serial_number_unit']
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

                // Set paper to landscape and margins
                $pdf->setPaper('a4', 'landscape');

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

