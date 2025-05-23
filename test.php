<?php

require 'src/Route2.php';

use Wilaak\Http\Route2;

// Helper function to send JSON responses with appropriate headers and status code
function send_json_response($data, int $status = 200): void {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
}

// Instantiate the router, it will automatically detect the request method and URI unless you provide them
// $router = new Route2($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
$router = new Route2();

// Middleware that runs before the route handler 
$router->before(function() {
    header('X-Powered-By: Route2');
});

// Define a group of API routes that respond with JSON
$router->group('/api', function($router) {
    $router->get('/hello/{name?}', function($name = 'World') {
        send_json_response(['message' => "Hello, $name!"]);
    });

    // Handle cases where the method is not allowed or the route is not found for the API group
    if ($router->allowedMethods) {
        header('Allow: ' . implode(', ', $router->allowedMethods));
        send_json_response([
            'error' => 'Method Not Allowed',
            'allowed_methods' => $router->allowedMethods
        ], 405);
    } else {
        send_json_response(['error' => 'Not Found'], 404);
    }
    exit;
});

// Define a group of admin routes that respond with HTML
$router->group('/admin', function($router) {
    $router->get('/dashboard', function () {
        ?>
            <h1>Admin Dashboard</h1>
            <p>Welcome to the admin dashboard!</p>
        <?php
    });
});

// Global fallback for routes that do not match any defined route
if ($router->allowedMethods) {
    header('Allow: ' . implode(', ', $router->allowedMethods));
    http_response_code(405);
    ?>
        <h1>Method Not Allowed</h1>
        <p>The requested method is not allowed for this URL.</p>
        <p>Allowed methods: <?=implode(', ', $router->allowedMethods)?></p>
    <?php
} else {
    http_response_code(404);
    ?>
        <h1>Not Found</h1>
        <p>The requested URL was not found on this server.</p>
    <?php
}