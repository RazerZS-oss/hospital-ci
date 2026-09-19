<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class M_pasien extends CI_Model {

    public function count_all() {
        return $this->db->count_all('patients');
    }

    public function get_all() {
        $this->db->order_by('id', 'DESC');
        return $this->db->get('patients')->result_array();
    }

    public function insert($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->insert('patients', $data);
    }
}
