<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Pasien extends MY_Controller {

    public function __construct() {
        parent::__construct();
        if (!$this->session->userdata('isLoggedIn')) {
            redirect('login');
        }
        $this->load->model('M_pasien');
    }

    public function index() {
        $data['patients'] = $this->M_pasien->get_all();
        $this->load->view('pasien/list', $data);
    }

    public function create() {
        if ($this->input->method() === 'post') {
            $data = array(
                'name'                  => $this->input->post('name'),
                'medical_record_number' => $this->input->post('medical_record_number'),
                'blood_type'            => $this->input->post('blood_type')
            );

            $this->M_pasien->insert($data);
            $this->session->set_flashdata('success', 'Patient added successfully.');
        }

        redirect('patients');
    }
}
