<?php
namespace App\Controllers;
use App\Models\UserModel;

class AuthController extends BaseController
{
    public function index()
    {
        // If already logged in, go to dashboard
        if (session()->get('isLoggedIn')) {
            return redirect()->to('/dashboard');
        }
        return view('auth/login');
    }

    public function login()
    {
        $session = session();
        $model = new UserModel();
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        $user = $model->where('username', $username)->first();

        if ($user) {
            if (password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    return redirect()->to('/login')->with('error', 'Account is suspended or inactive.');
                }

                $ses_data = [
                    'id'         => $user['id'],
                    'username'   => $user['username'],
                    'name'       => $user['name'],
                    'role'       => $user['role'],
                    'isLoggedIn' => TRUE
                ];
                $session->set($ses_data);
                
                // Security Audit Log could go here
                
                return redirect()->to('/dashboard');
            }
        }
        return redirect()->to('/login')->with('error', 'Invalid Credentials.');
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login');
    }
}
