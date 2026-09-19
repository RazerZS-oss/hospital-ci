<?php
defined('BASEPATH') OR exit('No direct script access allowed');

#[AllowDynamicProperties]
class Registration_service {

    protected CI_Controller $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('registration/Visit_model', 'visit_model');
    }

    /**
     * Enforces clinical business rules before outpatient queue assignment
     *
     * @param string $patient_id    Patient UUID
     * @param string $polyclinic_id Polyclinic UUID
     * @param string $doctor_id     Doctor UUID
     * @param string $visit_date    Visit Date 'YYYY-MM-DD'
     * @return array Queue registration outcome
     * @throws InvalidArgumentException When business validation fails
     */
    public function process_outpatient_registration(
        string|int $patient_id,
        string $polyclinic_id,
        string|int $doctor_id,
        string $visit_date
    ): array {
        // Business Rule 1: Prevent duplicate active visits on the same clinic/doctor on the same date
        $existing = $this->ci->db->where([
            'patient_id'    => $patient_id,
            'polyclinic_id' => $polyclinic_id,
            'doctor_id'     => $doctor_id,
            'visit_date'    => $visit_date
        ])->where_not_in('queue_status', ['CANCELLED'])->get('visits')->row();

        if ($existing) {
            throw new InvalidArgumentException(
                "Patient is already registered for this clinic today. Visit: {$existing->visit_number}, Queue: #{$existing->queue_number}"
            );
        }

        // Business Rule 2: Verify patient exists in registry
        $patient = $this->ci->db->where('id', $patient_id)->get('patients')->row();
        if (!$patient) {
            throw new InvalidArgumentException("Patient record not found in hospital master database.");
        }

        // Business Rule 3: Verify doctor is active
        $doctor = $this->ci->db->where('id', $doctor_id)->get('doctors')->row();
        if (!$doctor || !$doctor->is_active) {
            throw new InvalidArgumentException("Selected doctor is not active or unavailable.");
        }

        // Delegate atomic allocation to transactional model
        return $this->ci->visit_model->register_patient_queue(
            $patient_id,
            $polyclinic_id,
            $doctor_id,
            $visit_date
        );
    }
}
