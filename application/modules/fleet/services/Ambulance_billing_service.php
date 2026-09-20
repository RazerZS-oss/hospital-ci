<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Fleet Management: Ambulance Dispatch & Dynamic Tariff Billing Service
 *
 * Implements distance-tiered tariff calculations, clinical condition/equipment surcharges,
 * toll & parking fees, and automated posting to hospital billing_invoices and billing_details tables.
 *
 * Fully compliant with PHP 8.4-FPM and PostgreSQL 15 ACID transactions.
 */
#[AllowDynamicProperties]
class Ambulance_billing_service {

    protected CI_Controller $ci;
    protected CI_DB_query_builder $db;

    // Tariff Rates (in IDR)
    protected float $base_flagfall_rate     = 350000.00; // Includes first 5.0 km
    protected float $base_distance_limit_km = 5.0;
    protected float $rate_per_km_tier1      = 15000.00;  // 5.1 km to 20.0 km (first 15 km excess)
    protected float $rate_per_km_tier2      = 20000.00;  // > 20.0 km (> 15 km excess)

    // Specialized Clinical Vehicle Equipment Surcharges (in IDR)
    protected array $vehicle_surcharges = [
        'ADVANCED_LIFE_SUPPORT_ALS' => 250000.00, // Defibrillator, transport ventilator, infusion pumps
        'NEONATAL_ICU'              => 350000.00, // Transport incubator & specialized neonatologist crew
        'BASIC_TRANSPORT'           => 0.00       // Standard stretcher & basic first aid
    ];

    // Clinical Condition / Emergency Incident Surcharges (in IDR)
    protected array $condition_surcharges = [
        'CARDIAC_EMERGENCY'  => 250000.00, // Resuscitation, monitor/defibrillator setup
        'TRAUMA'             => 150000.00, // Spinal immobilization, extrication splints
        'NEONATAL_CRITICAL'  => 350000.00, // High-acuity neonatal intensive care
        'RESPIRATORY_ARREST' => 250000.00, // Intubation, mechanical ventilation
        'STROKE_CRITICAL'    => 200000.00, // Rapid neurological stabilization
    ];

    public function __construct() {
        $this->ci =& get_instance();
        $this->db =& $this->ci->db;
        $this->ci->load->database();
    }

    /**
     * Get active tariff configuration.
     *
     * @return array
     */
    public function get_tariffs(): array {
        return [
            'base_flagfall_rate'     => $this->base_flagfall_rate,
            'base_distance_limit_km' => $this->base_distance_limit_km,
            'rate_per_km_tier1'      => $this->rate_per_km_tier1,
            'rate_per_km_tier2'      => $this->rate_per_km_tier2,
            'vehicle_surcharges'     => $this->vehicle_surcharges,
            'condition_surcharges'   => $this->condition_surcharges,
        ];
    }

    /**
     * Calculates distance-tiered tariff breakdown.
     *
     * @param float $distance_km Distance in kilometers (must be >= 0)
     * @return array [base_fee, distance_fee, excess_km]
     * @throws InvalidArgumentException On negative distance
     */
    public function calculate_tiered_distance_fee(float $distance_km): array {
        if ($distance_km < 0.0) {
            throw new InvalidArgumentException("Invalid ambulance dispatch distance: {$distance_km} km. Distance cannot be negative.");
        }

        $base_fee     = $this->base_flagfall_rate;
        $distance_fee = 0.00;
        $excess_km    = 0.00;

        if ($distance_km > $this->base_distance_limit_km) {
            $excess_km = round($distance_km - $this->base_distance_limit_km, 2);
            if ($excess_km <= 15.0) {
                // Tier 1: 5.1 km to 20.0 km
                $distance_fee = $excess_km * $this->rate_per_km_tier1;
            } else {
                // Tier 2: > 20.0 km (Tier 1 for 15km + Tier 2 for remainder)
                $distance_fee = (15.0 * $this->rate_per_km_tier1) + (($excess_km - 15.0) * $this->rate_per_km_tier2);
            }
        }

        return [
            'base_fee'     => $base_fee,
            'distance_fee' => round($distance_fee, 2),
            'excess_km'    => $excess_km
        ];
    }

    /**
     * Calculates clinical surcharge based on vehicle equipment and clinical condition severity.
     * Selects the highest required clinical tier to prevent redundant double-billing.
     *
     * @param string $vehicle_type
     * @param string $incident_type
     * @return float
     */
    public function calculate_clinical_surcharge(string $vehicle_type, string $incident_type = ''): float {
        $v_key = strtoupper(trim($vehicle_type));
        $equipment_fee = $this->vehicle_surcharges[$v_key] ?? 0.00;

        $c_key = strtoupper(trim($incident_type));
        $condition_fee = $this->condition_surcharges[$c_key] ?? 0.00;

        return max($equipment_fee, $condition_fee);
    }

    /**
     * Calculates complete tariff breakdown without database side effects.
     *
     * @param float $distance_km
     * @param string $vehicle_type
     * @param string $incident_type
     * @param float $toll_fees
     * @return array
     * @throws InvalidArgumentException
     */
    public function calculate_fare(float $distance_km, string $vehicle_type, string $incident_type = '', float $toll_fees = 0.0): array {
        if ($toll_fees < 0.0) {
            throw new InvalidArgumentException("Invalid toll fees: {$toll_fees}. Toll fees cannot be negative.");
        }

        $distance_breakdown  = $this->calculate_tiered_distance_fee($distance_km);
        $clinical_surcharge  = $this->calculate_clinical_surcharge($vehicle_type, $incident_type);
        $total_charge        = $distance_breakdown['base_fee'] + $distance_breakdown['distance_fee'] + $clinical_surcharge + $toll_fees;

        return [
            'distance_km'            => $distance_km,
            'flagfall_base_fee'      => $distance_breakdown['base_fee'],
            'tiered_distance_fee'    => $distance_breakdown['distance_fee'],
            'excess_km'              => $distance_breakdown['excess_km'],
            'clinical_surcharge'     => $clinical_surcharge,
            'toll_and_parking'       => round($toll_fees, 2),
            'total_ambulance_charge' => round($total_charge, 2)
        ];
    }

    /**
     * Calculates dynamic tariff and atomically posts line items to billing_details and billing_invoices.
     *
     * @param string $dispatch_uuid Primary key of ambulance_dispatch_logs
     * @param string|null $invoice_uuid Optional target invoice UUID; if null, resolves via visit_id
     * @return array Calculation breakdown and created billing_detail record
     * @throws InvalidArgumentException On invalid inputs, negative values, or missing patient/visit
     * @throws RuntimeException On missing records, duplicate billing, or database failure
     */
    public function calculate_and_post_ambulance_billing(string $dispatch_uuid, ?string $invoice_uuid = null): array {
        // Validate UUID format
        if (empty($dispatch_uuid) || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $dispatch_uuid)) {
            throw new InvalidArgumentException("Invalid ambulance dispatch UUID format: '{$dispatch_uuid}'.");
        }

        if ($invoice_uuid !== null && !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $invoice_uuid)) {
            throw new InvalidArgumentException("Invalid target invoice UUID format: '{$invoice_uuid}'.");
        }

        // 1. Fetch Dispatch Log with Joined Ambulance Details
        $this->db->select('d.*, a.call_sign, a.vehicle_type, a.license_plate');
        $this->db->from('ambulance_dispatch_logs d');
        $this->db->join('ambulances a', 'a.id = d.ambulance_id', 'INNER');
        $this->db->where('d.id', $dispatch_uuid);
        $dispatch = $this->db->get()->row_array();

        if (!$dispatch) {
            throw new RuntimeException("Ambulance dispatch log not found (UUID: {$dispatch_uuid}).");
        }

        // 2. Prevent Double Billing (Already Billed Dispatch Log)
        $already_billed = $this->db->get_where('billing_details', [
            'service_category' => 'AMBULANCE',
            'item_code'        => 'AMB-' . $dispatch['dispatch_code']
        ])->row_array();

        if ($already_billed) {
            throw new RuntimeException("Ambulance dispatch log has already been billed (Code: {$dispatch['dispatch_code']}, Detail ID: {$already_billed['id']}).");
        }

        // Check dispatch status: CANCELLED dispatches cannot be billed
        if (($dispatch['dispatch_status'] ?? '') === 'CANCELLED') {
            throw new RuntimeException("Cannot generate billing for a CANCELLED ambulance dispatch (Code: {$dispatch['dispatch_code']}).");
        }

        // 3. Validate Distance and Toll Fees
        $distance_km = (float)$dispatch['distance_km'];
        $toll_fees   = (float)$dispatch['toll_fees'];

        if ($distance_km < 0.0) {
            throw new InvalidArgumentException("Invalid ambulance dispatch distance: {$distance_km} km. Distance cannot be negative.");
        }

        if ($toll_fees < 0.0) {
            throw new InvalidArgumentException("Invalid toll fees: {$toll_fees}. Toll fees cannot be negative.");
        }

        // 4. Validate and Resolve Patient and Visit Reference
        $target_invoice_id = $invoice_uuid;
        $patient_id        = !empty($dispatch['patient_id']) ? (int)$dispatch['patient_id'] : null;

        if ($target_invoice_id !== null) {
            $existing_inv = $this->db->get_where('billing_invoices', ['id' => $target_invoice_id])->row_array();
            if (!$existing_inv) {
                throw new RuntimeException("Target billing invoice not found (UUID: {$target_invoice_id}).");
            }
            if (!$patient_id) {
                $patient_id = (int)$existing_inv['patient_id'];
            }
        } else {
            // Must resolve via visit_id
            if (empty($dispatch['visit_id'])) {
                throw new InvalidArgumentException("Missing visit reference for dispatch log (Code: {$dispatch['dispatch_code']}). An active visit or valid invoice UUID is required for billing.");
            }

            $visit = $this->db->get_where('visits', ['id' => $dispatch['visit_id']])->row_array();
            if (!$visit) {
                throw new RuntimeException("Referenced visit not found (UUID: {$dispatch['visit_id']}).");
            }

            if (!$patient_id) {
                $patient_id = (int)$visit['patient_id'];
            }

            if (empty($patient_id)) {
                throw new InvalidArgumentException("Missing patient reference for dispatch log (Code: {$dispatch['dispatch_code']}).");
            }

            $patient = $this->db->get_where('patients', ['id' => $patient_id])->row_array();
            if (!$patient) {
                throw new RuntimeException("Referenced patient not found (ID: {$patient_id}).");
            }
        }

        // 5. Calculate Dynamic Tariff Breakdown
        $v_type        = (string)$dispatch['vehicle_type'];
        $incident_type = (string)($dispatch['incident_type'] ?? '');
        $fare          = $this->calculate_fare($distance_km, $v_type, $incident_type, $toll_fees);
        $total_charge  = $fare['total_ambulance_charge'];

        // 6. Execute PostgreSQL 15 ACID Transaction
        $this->db->trans_begin();

        try {
            // Concurrency guard: Re-verify unbilled state within transaction
            $concurrency_check = $this->db->get_where('billing_details', [
                'service_category' => 'AMBULANCE',
                'item_code'        => 'AMB-' . $dispatch['dispatch_code']
            ])->row_array();

            if ($concurrency_check) {
                $this->db->trans_rollback();
                throw new RuntimeException("Ambulance dispatch log has already been billed (Code: {$dispatch['dispatch_code']}).");
            }

            // Resolve target billing invoice
            if (!$target_invoice_id) {
                // Find existing invoice for this visit
                $inv = $this->db->where('visit_id', $dispatch['visit_id'])
                    ->where_in('payment_status', ['UNPAID', 'PARTIALLY_PAID'])
                    ->order_by('created_at', 'DESC')
                    ->get('billing_invoices')
                    ->row_array();

                if ($inv) {
                    $target_invoice_id = $inv['id'];
                } else {
                    // Generate standalone invoice
                    $inv_uuid_row      = $this->db->query("SELECT gen_random_uuid() AS uid")->row();
                    $target_invoice_id = $inv_uuid_row->uid;
                    $inv_num           = 'INV-AMB-' . date('Ymd') . '-' . rand(1000, 9999);

                    $inv_inserted = $this->db->insert('billing_invoices', [
                        'id'                   => $target_invoice_id,
                        'invoice_number'       => $inv_num,
                        'visit_id'             => $dispatch['visit_id'],
                        'patient_id'           => $patient_id,
                        'total_amount'         => 0.00,
                        'discount_amount'      => 0.00,
                        'tax_amount'           => 0.00,
                        'final_payable_amount' => 0.00,
                        'payment_status'       => 'UNPAID'
                    ]);

                    if (!$inv_inserted || $this->db->trans_status() === FALSE) {
                        $this->db->trans_rollback();
                        throw new RuntimeException("Transaction failure: Failed to create billing invoice for dispatch {$dispatch['dispatch_code']}.");
                    }
                }
            }

            // Insert line item into billing_details table
            $detail_uuid_row = $this->db->query("SELECT gen_random_uuid() AS uid")->row();
            $detail_id       = $detail_uuid_row->uid;

            $item_description = sprintf(
                "Ambulance Emergency Dispatch [%s / %s] - %.1f km (Base: Rp %s, Distance: Rp %s, Equip: Rp %s, Tolls: Rp %s)",
                $dispatch['call_sign'],
                $v_type,
                $distance_km,
                number_format($fare['flagfall_base_fee'], 0, ',', '.'),
                number_format($fare['tiered_distance_fee'], 0, ',', '.'),
                number_format($fare['clinical_surcharge'], 0, ',', '.'),
                number_format($fare['toll_and_parking'], 0, ',', '.')
            );

            $detail_inserted = $this->db->insert('billing_details', [
                'id'               => $detail_id,
                'invoice_id'       => $target_invoice_id,
                'service_category' => 'AMBULANCE',
                'item_code'        => 'AMB-' . $dispatch['dispatch_code'],
                'item_name'        => mb_substr($item_description, 0, 255),
                'quantity'         => 1,
                'unit_price'       => $total_charge,
                'subtotal'         => $total_charge
            ]);

            if (!$detail_inserted || $this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                throw new RuntimeException("Transaction failure: Failed to insert line item into billing_details.");
            }

            // Update parent invoice total amount with parameterized query
            $invoice_updated = $this->db->query("
                UPDATE billing_invoices
                SET total_amount = total_amount + ?,
                    final_payable_amount = final_payable_amount + ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$total_charge, $total_charge, $target_invoice_id]);

            if (!$invoice_updated || $this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                throw new RuntimeException("Transaction failure: Failed to update invoice totals.");
            }

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                throw new RuntimeException("Transaction failure during ambulance billing insertion.");
            }

            $this->db->trans_commit();

            return [
                'status'            => 'success',
                'dispatch_code'     => $dispatch['dispatch_code'],
                'invoice_id'        => $target_invoice_id,
                'billing_detail_id' => $detail_id,
                'breakdown'         => [
                    'distance_km'             => $fare['distance_km'],
                    'flagfall_base_fee'       => $fare['flagfall_base_fee'],
                    'tiered_distance_fee'     => $fare['tiered_distance_fee'],
                    'clinical_surcharge'      => $fare['clinical_surcharge'],
                    'toll_and_parking'        => $fare['toll_and_parking'],
                    'total_ambulance_charge'  => $fare['total_ambulance_charge']
                ]
            ];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            throw $e;
        }
    }
}
