<?php
use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'AuthController::index');
$routes->get('login', 'AuthController::index');
$routes->post('login', 'AuthController::login');
$routes->get('logout', 'AuthController::logout');

// 🔒 Protected Routes (Must be logged in)
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('dashboard', 'DashboardController::index');
    
    $routes->get('patients', 'PatientController::index');
    $routes->post('patients/create', 'PatientController::create');
    
    // Only accessible to admins (example of RoleGuard in action)
    $routes->get('doctors', 'DoctorController::index', ['filter' => 'role:admin']);
});
