<?php
namespace App\Database\Seeds;
use CodeIgniter\Database\Seeder;
use App\Models\UserModel;

class UserSeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();

        // Admin Account
        $userModel->insert([
            'username' => 'admin',
            'password' => 'admin123', // Will be hashed automatically by UserModel
            'name'     => 'IT Admin Roy',
            'role'     => 'admin',
            'status'   => 'active'
        ]);

        // Doctor Account
        $userModel->insert([
            'username' => 'drbudi',
            'password' => 'doctor123',
            'name'     => 'Dr. Budi Santoso',
            'role'     => 'doctor',
            'status'   => 'active'
        ]);
    }
}
