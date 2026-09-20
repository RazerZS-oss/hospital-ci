<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Executive Dashboard & Statistical Analytics Model
 *
 * Implements high-performance analytical queries using PostgreSQL 15 
 * Common Table Expressions (CTEs) and Window Functions for Bed Occupancy Rate (BOR)
 * and ICD-10 Epidemiological Disease Surveillance.
 *
 * Compatible with PHP 8.4-FPM and PostgreSQL 15.
 */
#[AllowDynamicProperties]
class Executive_dashboard_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Calculates the monthly Bed Occupancy Rate (BOR) for hospital wards.
     * Evaluates against WHO & Ministry of Health standard utilization benchmarks:
     * - < 60%: Underutilized
     * - 60% - 75%: Moderate
     * - 75% - 85%: Optimal (WHO Standard)
     * - > 85%: Overcrowded
     *
     * Formula:
     * BOR (%) = (Total Inpatient Bed-Days in Month / (Total Available Beds * Days in Month)) * 100
     *
     * @param string|null $target_date Format 'YYYY-MM-DD' or null for current month
     * @return array Analytical BOR metrics and clinical capacity assessment
     */
    public function get_current_month_bor(?string $target_date = null): array {
        $timestamp = $target_date ? strtotime($target_date) : time();
        if ($timestamp === false) {
            $timestamp = time();
        }
        $effective_date = date('Y-m-d H:i:s', $timestamp);

        $sql = "
            WITH date_bounds AS (
                SELECT 
                    DATE_TRUNC('month', ?::timestamptz) AS month_start,
                    DATE_TRUNC('month', ?::timestamptz) + INTERVAL '1 month' AS month_end,
                    EXTRACT(DAY FROM (DATE_TRUNC('month', ?::timestamptz) + INTERVAL '1 month' - INTERVAL '1 day'))::int AS days_in_month
            ),
            capacity AS (
                SELECT 
                    COALESCE(SUM(total_beds), 0)::int AS total_available_beds
                FROM hospital_wards
                WHERE is_active = TRUE
            ),
            bed_days_calc AS (
                SELECT 
                    ia.id AS admission_id,
                    GREATEST(
                        1,
                        DATE(LEAST(COALESCE(ia.discharged_at, CURRENT_TIMESTAMP), d.month_end)) - 
                        DATE(GREATEST(ia.admitted_at, d.month_start))
                    ) AS days_occupied
                FROM inpatient_admissions ia
                CROSS JOIN date_bounds d
                WHERE ia.admitted_at < d.month_end
                  AND (ia.discharged_at IS NULL OR ia.discharged_at >= d.month_start)
            )
            SELECT 
                d.month_start::date AS period_start,
                (d.month_end - INTERVAL '1 day')::date AS period_end,
                d.days_in_month,
                c.total_available_beds,
                COALESCE(SUM(b.days_occupied), 0)::int AS total_patient_bed_days,
                (c.total_available_beds * d.days_in_month) AS total_bed_capacity_days,
                CASE 
                    WHEN (c.total_available_beds * d.days_in_month) > 0 THEN
                        ROUND(
                            (COALESCE(SUM(b.days_occupied), 0)::numeric / (c.total_available_beds * d.days_in_month)::numeric) * 100.0, 
                            2
                        )
                    ELSE 0.00
                END AS bor_percentage
            FROM date_bounds d
            CROSS JOIN capacity c
            LEFT JOIN bed_days_calc b ON TRUE
            GROUP BY d.month_start, d.month_end, d.days_in_month, c.total_available_beds
        ";

        try {
            $query = $this->db->query($sql, [$effective_date, $effective_date, $effective_date]);
            $row = $query ? $query->row_array() : null;
        } catch (Throwable $e) {
            log_message('error', 'Executive_dashboard_model::get_current_month_bor failed: ' . $e->getMessage());
            $row = null;
        }

        $days_in_month_fallback = (int)date('t', $timestamp);
        if (!$row) {
            $row = [
                'period_start'            => date('Y-m-01', $timestamp),
                'period_end'              => date('Y-m-t', $timestamp),
                'days_in_month'           => $days_in_month_fallback,
                'total_available_beds'    => 0,
                'total_patient_bed_days'  => 0,
                'total_bed_capacity_days' => 0,
                'bor_percentage'          => 0.00
            ];
        }

        $total_beds             = (int)($row['total_available_beds'] ?? 0);
        $days_in_month          = (int)($row['days_in_month'] ?? $days_in_month_fallback);
        $total_bed_capacity     = (int)($row['total_bed_capacity_days'] ?? ($total_beds * $days_in_month));
        $total_patient_bed_days = (int)($row['total_patient_bed_days'] ?? 0);

        // Application-level division by zero defense
        if ($total_bed_capacity > 0) {
            $bor = (float)($row['bor_percentage'] ?? round(($total_patient_bed_days / $total_bed_capacity) * 100.0, 2));
        } else {
            $bor = 0.00;
        }

        // Classify against World Health Organization (WHO) benchmarks:
        // <60% Underutilized, 60-75% Moderate, 75-85% Optimal, >85% Overcrowded
        if ($bor < 60.0) {
            $benchmark_status = 'UNDERUTILIZED';
            $interpretation   = 'Suboptimal bed utilization (<60%). Hospital capacity is underemployed; consider outpatient service expansion or marketing.';
        } elseif ($bor < 75.0) {
            $benchmark_status = 'MODERATE';
            $interpretation   = 'Moderate capacity utilization (60%-75%) within safe margin, though below optimal operational efficiency.';
        } elseif ($bor <= 85.0) {
            $benchmark_status = 'OPTIMAL';
            $interpretation   = 'Ideal bed utilization rate meeting international WHO standard (75%-85%). Maximizes resource use while maintaining emergency buffer.';
        } else {
            $benchmark_status = 'OVERCROWDED';
            $interpretation   = 'Severe bed strain (>85%). High risk of nosocomial infection transmission, staff burnout, and emergency intake refusal.';
        }

        return [
            'period' => [
                'start_date'    => $row['period_start'],
                'end_date'      => $row['period_end'],
                'days_in_month' => $days_in_month
            ],
            'metrics' => [
                'total_beds_available'    => $total_beds,
                'total_patient_bed_days'  => $total_patient_bed_days,
                'total_bed_capacity_days' => $total_bed_capacity,
                'bor_percentage'          => $bor
            ],
            'clinical_efficiency' => [
                'who_benchmark_status' => $benchmark_status,
                'benchmark_status'     => $benchmark_status,
                'interpretation'       => $interpretation,
                'target_benchmark'     => '75.00% - 85.00%'
            ]
        ];
    }

    /**
     * Retrieves Top 10 primary diagnoses (ICD-10) for the specified month
     * using PostgreSQL DENSE_RANK() and SUM() OVER () window functions.
     *
     * @param string|null $target_date Format 'YYYY-MM-DD' or null for current month
     * @return array Top 10 ranked diseases with case counts and percentage prevalence
     */
    public function get_top_10_diseases_current_month(?string $target_date = null): array {
        $timestamp = $target_date ? strtotime($target_date) : time();
        if ($timestamp === false) {
            $timestamp = time();
        }
        $effective_date = date('Y-m-d H:i:s', $timestamp);

        $sql = "
            WITH monthly_diagnoses AS (
                SELECT 
                    mr.primary_icd10_code AS icd10_code,
                    COALESCE(icd.description_en, 'Unspecified Clinical Condition') AS diagnosis_name,
                    COALESCE(icd.category, 'General Medicine') AS disease_category,
                    COUNT(*) AS total_cases,
                    COUNT(DISTINCT mr.patient_id) AS unique_patients
                FROM medical_records mr
                LEFT JOIN icd10_codes icd ON icd.code = mr.primary_icd10_code
                WHERE mr.created_at >= DATE_TRUNC('month', ?::timestamptz)
                  AND mr.created_at < DATE_TRUNC('month', ?::timestamptz) + INTERVAL '1 month'
                  AND mr.primary_icd10_code IS NOT NULL
                  AND mr.primary_icd10_code <> ''
                GROUP BY mr.primary_icd10_code, icd.description_en, icd.category
            ),
            ranked_diagnoses AS (
                SELECT 
                    icd10_code,
                    diagnosis_name,
                    disease_category,
                    total_cases,
                    unique_patients,
                    DENSE_RANK() OVER (ORDER BY total_cases DESC) AS rank_position,
                    SUM(total_cases) OVER () AS total_monthly_diagnoses,
                    ROUND((total_cases::numeric / NULLIF(SUM(total_cases) OVER (), 0)::numeric) * 100.0, 2) AS prevalence_pct
                FROM monthly_diagnoses
            )
            SELECT 
                rank_position,
                icd10_code,
                diagnosis_name,
                disease_category,
                total_cases,
                unique_patients,
                COALESCE(prevalence_pct, 0.00) AS prevalence_pct,
                total_monthly_diagnoses
            FROM ranked_diagnoses
            WHERE rank_position <= 10
            ORDER BY rank_position ASC, total_cases DESC
        ";

        try {
            $query = $this->db->query($sql, [$effective_date, $effective_date]);
            $rows = $query ? $query->result_array() : [];
        } catch (Throwable $e) {
            log_message('error', 'Executive_dashboard_model::get_top_10_diseases_current_month failed: ' . $e->getMessage());
            $rows = [];
        }

        $total_cases = !empty($rows) ? (int)$rows[0]['total_monthly_diagnoses'] : 0;

        return [
            'surveillance_month'      => date('F Y', $timestamp),
            'total_diagnosed_cases'   => $total_cases,
            'top_diseases_count'      => count($rows),
            'rankings'                => array_map(function($item) {
                return [
                    'rank'             => (int)$item['rank_position'],
                    'icd10_code'       => $item['icd10_code'],
                    'diagnosis_name'   => $item['diagnosis_name'],
                    'category'         => $item['disease_category'],
                    'case_count'       => (int)$item['total_cases'],
                    'unique_patients'  => (int)$item['unique_patients'],
                    'prevalence_rate'  => (float)$item['prevalence_pct'] . '%'
                ];
            }, $rows)
        ];
    }
}
