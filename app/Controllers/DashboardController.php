<?php
namespace App\Controllers;
use App\Models\PatientModel;
use App\Models\DoctorModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $patientModel = new PatientModel();
        $doctorModel = new DoctorModel();

        $data = [
            'total_patients' => $patientModel->countAllResults(),
            'total_doctors'  => $doctorModel->countAllResults(),
            'user_role'      => session()->get('role'),
            'user_name'      => session()->get('name')
        ];

        return view('dashboard/index', $data);
    }
}
