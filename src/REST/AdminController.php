register_rest_route($this->namespace, '/admin/health-check', [
    'methods'             => 'GET',
    'callback'            => [$this, 'health_check'],
    'permission_callback' => [$this, 'require_admin'],
]);
