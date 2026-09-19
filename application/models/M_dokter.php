<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class M_dokter extends CI_Model {

    public function count_all() {
        return $this->db->count_all('doctors');
    }

    public function get_all() {
        $this->db->order_by('id', 'ASC');
        return $this->db->get('doctors')->result_array();
    }
}
