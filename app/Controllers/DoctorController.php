<?php

namespace App\Controllers;

use App\Models\DoctorModel;

class DoctorController extends BaseController
{
    public function index()
    {
        $doctorModel = new DoctorModel();
        
        $data = [
            'doctors' => $doctorModel->findAll(),
        ];
        
        return view('doctors/list', $data);
    }
}
