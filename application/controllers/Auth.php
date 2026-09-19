<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('M_user');
    }

    public function index() {
        if ($this->session->userdata('isLoggedIn')) {
            redirect('dashboard');
        }
        $this->load->view('auth/login');
    }

    public function login() {
        $username = $this->input->post('username');
        $password = $this->input->post('password');

        $user = $this->M_user->get_by_username($username);

        if ($user) {
            if (password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    $this->session->set_flashdata('error', 'Account is inactive or suspended.');
                    redirect('login');
                }

                $session_data = array(
                    'id'         => $user['id'],
                    'username'   => $user['username'],
                    'name'       => $user['name'],
                    'role'       => $user['role'],
                    'isLoggedIn' => TRUE
                );
                $this->session->set_userdata($session_data);

                redirect('dashboard');
            }
        }

        $this->session->set_flashdata('error', 'Invalid Credentials.');
        redirect('login');
    }

    public function logout() {
        $this->session->sess_destroy();
        redirect('login');
    }
}
