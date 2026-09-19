<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dokter extends MY_Controller {

    public function __construct() {
        parent::__construct();
        if (!$this->session->userdata('isLoggedIn')) {
            redirect('login');
        }
        $this->load->model('M_dokter');
    }

    public function index() {
        $data['doctors'] = $this->M_dokter->get_all();
        $this->load->view('dokter/list', $data);
    }
}
