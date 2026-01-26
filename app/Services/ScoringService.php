<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerKpiScore;
use App\Models\KPI;
use App\Models\DailyGoal;
use App\Models\Progress;

class ScoringService
{
    /**
     * Hitung dan update score untuk customer di KPI tertentu
     */
    public function calculateKpiScore($customerId, $kpiId, $userId)
    {
        $customer = Customer::findOrFail($customerId);
        $kpi = KPI::findOrFail($kpiId);

        // Hitung total tasks yang assigned ke customer ini
        $totalTasks = $this->getAssignedTasksCount($customerId, $kpiId, $userId);

        $completedTasks = Progress::where('customer_id', $customerId)
            ->where('kpi_id', $kpiId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->whereNotNull('time_completed')
            ->distinct('daily_goal_id')
            ->count('daily_goal_id');

        // Hitung completion rate
        $completionRate = $totalTasks > 0 ? ($completedTasks / $totalTasks) * 100 : 0;

        // Hitung poin yang diraih
        $earnedPoints = ($completionRate / 100) * $kpi->weight_point;

        // Update atau create record di customer_kpi_scores
        CustomerKpiScore::updateOrCreate(
            [
                'customer_id' => $customerId,
                'kpi_id' => $kpiId,
                'user_id' => $userId,
            ],
            [
                'tasks_completed' => $completedTasks,
                'tasks_total' => $totalTasks,
                'completion_rate' => round($completionRate, 2),
                'kpi_weight' => $kpi->weight_point,
                'earned_points' => round($earnedPoints, 2),
                'status' => $completionRate >= 100 ? 'completed' : 'active',
                'completed_at' => $completionRate >= 100 ? now() : null,
            ]
        );

        // Update total points di customer
        $this->updateCustomerTotalPoints($customerId);

        return [
            'completion_rate' => round($completionRate, 2),
            'earned_points' => round($earnedPoints, 2),
            'kpi_weight' => $kpi->weight_point,
        ];
    }

    private function getAssignedTasksCount($customerId, $kpiId, $userId)
    {
        $customer = Customer::findOrFail($customerId);

        // Mapping Sub-Kategori
        $groupMapping = [
            'UKPBJ' => 'KEDINASAN',
            'RUMAH SAKIT' => 'KEDINASAN',
            'KANTOR KEDINASAN' => 'KEDINASAN',
            'KANTOR BALAI' => 'KEDINASAN',
            'KELURAHAN' => 'KECAMATAN',
            'KECAMATAN' => 'KECAMATAN',
            'PUSKESMAS' => 'PUSKESMAS'
        ];

        $rawSub = strtoupper($customer->sub_category ?? '');
        $targetGoalGroup = $groupMapping[$rawSub] ?? $rawSub;

        // Mapping category ke daily_goal_type_id
        $categoryToTypeMapping = [
            'Pendidikan' => 1,
            'Pemerintah' => 2,
            'Web Inquiry Corporate' => 3,
            'Web Inquiry CNI' => 4,
            'Web Inquiry C&I' => 4,
        ];

        $expectedTypeId = $categoryToTypeMapping[$customer->category] ?? null;

        $query = DailyGoal::where('user_id', $userId)
            ->where('kpi_id', $kpiId)
            ->where('description', 'NOT LIKE', 'Auto-generated%');

        if (strtolower($customer->category ?? '') === 'pemerintah') {
            $query->where('daily_goal_type_id', 2);

            if (!empty($targetGoalGroup)) {
                $query->where(function($q) use ($targetGoalGroup) {
                    $q->whereNull('sub_category')
                      ->orWhere('sub_category', $targetGoalGroup);
                });
            }
        } else {
            if ($expectedTypeId !== null) {
                $query->where('daily_goal_type_id', $expectedTypeId);
            }
        }

        return $query->count();
    }

    /**
     * Update total points customer dari semua KPI
     */
    public function updateCustomerTotalPoints($customerId)
    {
        $customer = Customer::findOrFail($customerId);
        
        $totalEarned = CustomerKpiScore::where('customer_id', $customerId)
            ->sum('earned_points');

        $totalMax = CustomerKpiScore::where('customer_id', $customerId)
            ->sum('kpi_weight');

        $scorePercentage = $totalMax > 0 ? ($totalEarned / $totalMax) * 100 : 0;

        $customer->update([
            'earned_points' => round($totalEarned, 2),
            'max_points' => round($totalMax, 2),
            'score_percentage' => round($scorePercentage, 2),
        ]);

        return $customer;
    }

    /**
     * Hitung total points user dari semua customers
     */
    public function calculateUserTotalPoints($userId)
    {
        return CustomerKpiScore::where('user_id', $userId)
            ->sum('earned_points');
    }
}