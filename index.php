<?php
// simple front controller with rudimentary router
require_once __DIR__ . '/core/Router.php';

// sample routes (expand as needed)
Router::get('/login', function() {
    include __DIR__ . '/views/login.php';
});

Router::post('/login', function() {
    require_once __DIR__ . '/controllers/AuthController.php';
});

// leave routes
Router::post('/leave', function() {
    require_once __DIR__ . '/controllers/LeaveController.php';
});

// default catch-all could redirect to dashboard if authenticated
Router::get('/', function() {
    header('Location: views/dashboard.php');
});

// dispatch the requested URI
Router::dispatch();
?>