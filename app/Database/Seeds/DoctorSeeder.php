<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DoctorSeeder extends Seeder
{
    public function run()
    {
        $data = [
            [
                'name'           => 'Dr. Budi Santoso',
                'specialization' => 'Penyakit Dalam',
                'phone'          => '0812-3456-7890',
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ],
            [
                'name'           => 'Dr. Siti Aminah',
                'specialization' => 'Bedah Umum',
                'phone'          => '0813-4567-8901',
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ],
            [
                'name'           => 'Dr. Andi Wijaya',
                'specialization' => 'Dokter Anak',
                'phone'          => '0814-5678-9012',
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ],
        ];

        // Using Query Builder to insert the data
        $this->db->table('doctors')->insertBatch($data);
    }
}
