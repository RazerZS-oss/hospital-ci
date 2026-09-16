<?php
namespace App\Filters;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class RoleGuard implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $role = session()->get('role');
        // If arguments are passed in routes (e.g. ['filter' => 'role:admin,superadmin'])
        if ($arguments && !in_array($role, $arguments)) {
            return redirect()->to('/dashboard')->with('error', 'Access Denied: You do not have the required clearance level.');
        }
    }
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
