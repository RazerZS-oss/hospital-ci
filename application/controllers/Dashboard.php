<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends MY_Controller {

    public function __construct() {
        parent::__construct();
        if (!$this->session->userdata('isLoggedIn')) {
            redirect('login');
        }
        $this->load->model('M_pasien');
        $this->load->model('M_dokter');
    }

    public function index() {
        $data = array(
            'total_patients' => $this->M_pasien->count_all(),
            'total_doctors'  => $this->M_dokter->count_all(),
            'user_role'      => $this->session->userdata('role'),
            'user_name'      => $this->session->userdata('name')
        );

        $this->load->view('dashboard', $data);
    }
}
