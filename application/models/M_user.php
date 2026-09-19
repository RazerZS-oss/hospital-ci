<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class M_user extends CI_Model {

    public function get_by_username($username) {
        return $this->db->get_where('users', ['username' => $username])->row_array();
    }
}
