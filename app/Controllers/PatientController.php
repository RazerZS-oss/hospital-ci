<?php

namespace App\Controllers;

use App\Models\PatientModel;

class PatientController extends BaseController
{
    public function index()
    {
        $patientModel = new PatientModel();
        
        $data = [
            'patients' => $patientModel->findAll(),
        ];
        
        return view('patients/list', $data);
    }

    public function create()
    {
        // Check if the form is submitted
        if ($this->request->is('post')) {
            $patientModel = new PatientModel();
            
            // Get data from POST request
            $patientModel->save([
                'name'                  => $this->request->getPost('name'),
                'medical_record_number' => $this->request->getPost('medical_record_number'),
                'blood_type'            => $this->request->getPost('blood_type'),
            ]);
            
            // Redirect back to the patients list
            return redirect()->to('/patients')->with('success', 'Patient added successfully.');
        }

        // If it's a GET request, just return the list view 
        // (In a fuller app, this might return a specific create form view)
        return redirect()->to('/patients');
    }
}
