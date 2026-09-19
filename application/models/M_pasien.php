<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class M_pasien extends MY_Model {

    protected string $table = 'patients';

    public function count_all() {
        return $this->db->count_all($this->table);
    }

    public function get_all() {
        $this->db->order_by('id', 'DESC');
        return $this->db->get($this->table)->result_array();
    }

    public function insert($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        if (empty($data['mrn']) && !empty($data['medical_record_number'])) {
            $data['mrn'] = $data['medical_record_number'];
        }
        if (empty($data['full_name']) && !empty($data['name'])) {
            $data['full_name'] = $data['name'];
        }
        return $this->tracked_insert($data);
    }
}
