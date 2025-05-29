<?php

require 'src/Route2.php';

use Wilaak\Http\Route2;

$route = new Route2('GET', '/api/users/123');

$route->match(['GET'], '/api/users/{id}', function ($id) {
    echo "User ID: " . $id;
});