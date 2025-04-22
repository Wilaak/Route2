# Route2 🛣️

A simple routing library for PHP web applications.

### Features:
- **Parameters**: Flexible routing with required, optional and wildcard parameters.
- **Constraints**: Use regex or custom functions as parameter constraints.
- **Middleware**: Execute logic before and after route handling.
- **Grouping**: Organize routes with shared functionality for cleaner code.
- **Lightweight**: A single-file, no-frills dependency-free routing solution.

## Install

Install with composer:

    composer require wilaak/route2

Or simply include it in your project:

```php
require '/path/to/Route2.php'
```

Requires PHP 8.1 or newer

---

## Table of Contents

- [Usage](#usage)
  - [FrankenPHP Worker Mode](#frankenphp-worker-mode)
- [Basic Routing](#basic-routing)
  - [Available Methods](#available-methods)
  - [Multiple HTTP-verbs](#multiple-http-verbs)
- [Route Parameters](#route-parameters)
  - [Required Parameters](#required-parameters)
  - [Optional Parameters](#optional-parameters)
  - [Wildcard Parameters](#wildcard-parameters)
  - [Parameter Constraints](#parameter-constraints)
  - [Global Constraints](#global-constraints)
- [Middleware](#middleware)
  - [Registering Middleware](#registering-middleware)
- [Route Groups](#route-groups)
  - [Without prefix](#without-prefix)
- [Dispatching](#dispatching)
  - [Return Status Codes](#return-status-codes)
- [Troubleshooting](#troubleshooting)
  - [Accessing Routes](#accessing-routes)
- [Hide Scriptname From URL](#hide-scriptname-from-url)
  - [FrankenPHP](#frankenphp)
  - [NGINX](#nginx)
  - [Apache](#apache)
- [Performance](#performance)
  - [Enable Route Cache](#enable-route-cache)
  - [Benchmarks](#benchmarks)
- [License](#license)

## Usage

Here's a basic getting started example:

```php
<?php

require __DIR__.'/../vendor/autoload.php';

use Wilaak\Http\Route2;

Route2::get('/{world?}', function($world = 'World') {
    echo "Hello, $world!";
});

$status = Route2::dispatch();

if ($status['code'] === Route2::DISPATCH_FOUND) {
    return;
}

if ($status['code'] === Route2::DISPATCH_NOT_FOUND) {
    http_response_code(404);
    echo <<<HTML
        <h1>404 Page Not Found</h1> 
    HTML;
    return;
}

if ($status['code'] === Route2::DISPATCH_NOT_ALLOWED) {
    http_response_code(405);
    $allowedMethods = implode(',', $status['allowed_methods']);
    header('Allow: ' . $allowedMethods);
    echo <<<HTML
        <h1>405 Method Not Allowed</h1><br>
        <p>Allowed Methods: $allowedMethods</p>
        HTML;
    return;
}
```

### FrankenPHP Worker Mode

Boot your application once and keep it in memory by using [worker mode](https://frankenphp.dev/docs/worker/). This dramatically increases performance.

```php
<?php

ignore_user_abort(true);

require __DIR__.'/../vendor/autoload.php';

use Wilaak\Http\Route2;

Route2::get('/{world?}', function($world = 'World') {
    echo "Hello, $world!";
});

$handler = static function() {
    $status = Route2::dispatch();

    if ($status['code'] === Route2::DISPATCH_FOUND) {
        return;
    }

    if ($status['code'] === Route2::DISPATCH_NOT_FOUND) {
        http_response_code(404);
        echo <<<HTML
            <h1>404 Page Not Found</h1> 
        HTML;
        return;
    }

    if ($status['code'] === Route2::DISPATCH_NOT_ALLOWED) {
        http_response_code(405);
        $allowedMethods = implode(',', $status['allowed_methods']);
        header('Allow: ' . $allowedMethods);
        echo <<<HTML
            <h1>405 Method Not Allowed</h1><br>
            <p>Allowed Methods: $allowedMethods</p>
            HTML;
        return;
    }
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
```

## Basic Routing

The most basic routes accept a URI and a closure, providing a very simple and expressive method of defining routes and behavior:

```php
Route2::get('/greeting', function () {
    echo 'Hello World';
});
```

### Available Methods

The router allows you to register routes that respond to any HTTP verb:

```php
Route2::get($uri, $callback);
Route2::post($uri, $callback);
Route2::put($uri, $callback);
Route2::patch($uri, $callback);
Route2::delete($uri, $callback);
Route2::options($uri, $callback);
```

### Multiple HTTP-verbs

Sometimes you may need to register a route that responds to multiple HTTP-verbs. You may do so using the `match()` method. Or, you may even register a route that responds to all HTTP verbs using the `any()` method:

```php
Route2::match('get|post', '/', function() {
    // Matches any method you like
});

Route2::form('/', function() {
    // Matches GET and POST methods
});

Route2::any('/', function() {
    // Matches any HTTP method
});
```


## Route Parameters

Sometimes you will need to capture segments of the URI within your route. For example, you may need to capture a user's ID from the URL. You may do so by defining route parameters:

>**Note**: Parameters must be enclosed in forward slashes (e.g., `/{param}/`) and are always passed as strings to the controller.

You may define as many route parameters as required by your route:

```php
Route2::get('/posts/{post}/comments/{comment}', function ($post, $comment) {
    // ...
});
```

### Required Parameters

These parameters must be provided or the route is skipped.

```php
Route2::get('/user/{id}', function ($id) {
    echo 'User '.$id;
});
```

### Optional Parameters

Specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name.

>**Note**: Make sure to give the route's corresponding variable a default value:

```php
Route2::get('/user/{name?}', function (string $name = 'John') {
    echo $name;
});
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

>**Note**: Make sure to give the route's corresponding variable a default value:

```php
Route2::get('/somewhere/{any*}', function ($any = 'Empty') {
    // Matches everything after the parameter
});
```

### Parameter Constraints

You can constrain the format of your route parameters by using the named argument `expression`, which accepts an associative array where the key is the parameter name and the value is either a regex string or a function.

```php
Route2::get('/user/{id}', function ($id) {
    echo "User ID: $id";
}, expression: ['id' => '[0-9]+']);

Route2::get('/user/{id}', function ($id) {
    echo "User ID: $id";
}, expression: ['id' => is_numeric(...)]);
```

### Global Constraints

If you would like a route parameter to always be constrained by a given expression, you may use the `expression()` method. Routes added after this method will inherit the expression constraints.

```php
Route2::expression([
    'id' => is_numeric(...)
]);

Route2::get('/user/{id}', function ($id) {
    // Only executed if {id} is numeric...
});
```

## Middleware

Middleware inspect and filter HTTP requests entering your application. For instance, authentication middleware can redirect unauthenticated users to a login screen, while allowing authenticated users to proceed. Middleware can also handle tasks like logging incoming requests.

>**Note**: Middleware only run if a route is found.

### Registering Middleware

You may register middleware by using the `before()` and `after()` methods. Routes added after this method call will inherit the middleware. You may also assign a middleware to a specific route by using the named argument `middleware`:

```php
// Runs before the route callback
Route2::before(your_middleware(...));

// Runs after the route callback
Route2::after(function() {
    echo 'Terminating!';
});

// Runs before the route but after the inherited middleware
Route2::get('/', function() {
    // ...
}, middleware: fn() => print('I am also a middleware'));
```

## Route Groups

Route groups let you share attributes like middleware and expressions across multiple routes, avoiding repetition. Nested groups inherit attributes from their parent, similar to variable scopes.

```php
// Group routes under a common prefix
Route2::group('/admin', function () {
    // Middleware for all routes in this group and its nested groups
    Route2::before(function () {
        echo "Group middleware executed.<br>";
    });
    Route2::get('/dashboard', function () {
        echo "Admin Dashboard";
    });
    Route2::post('/settings', function () {
        echo "Admin Settings Updated";
    });
});
```

In this example, all routes within the group will share the `/admin` prefix.

### Without prefix

You may also define a group without a prefix by omitting the first argument and using the named argument `callback`.

```php
Route2::group(callback: function () {
    // ...
});
```

## Dispatching

A URI is dispatched by calling the `dispatch()` method. This method accepts an HTTP method and a URI. If none are provided it uses the `$_SERVER['REQUEST_METHOD']` and internal `getRelativeRequestUri()` method which returns the relative URI of the current HTTP request. (e.g., `/folder/index.php/myroute` → `/myroute`)

### Return Status Codes

The `dispatch()` method returns an array with a status code that is either `Route2::DISPATCH_FOUND`, `Route2::DISPATCH_NOT_FOUND` or `Route2::DISPATCH_NOT_ALLOWED`.

>**Note**: The HTTP specification requires that a `405 Method Not Allowed` response to include the `Allow:` header to detail available methods for the requested resource. Applications should use the `allowed_methods` array key to add this header when relaying a 405 response.

```php
$status = Route2::dispatch();

if ($status['code'] === Route2::DISPATCH_FOUND) {
    return;
}

if ($status['code'] === Route2::DISPATCH_NOT_FOUND) {
    http_response_code(404);
    echo <<<HTML
        <h1>404 Page Not Found</h1> 
    HTML;
    return;
}

if ($status['code'] === Route2::DISPATCH_NOT_ALLOWED) {
    http_response_code(405);
    $allowedMethods = implode(',', $status['allowed_methods']);
    header('Allow: ' . $allowedMethods);
    echo <<<HTML
        <h1>405 Method Not Allowed</h1><br>
        <p>Allowed Methods: $allowedMethods</p>
        HTML;
    return;
}
```

## Troubleshooting

### Accessing Routes

The simplest way to access your routes is to put the file in your folder and run it.

For example if you request ```http://your.site/yourscript.php/your/route``` it will automatically adjust to `/your/route`.

## Hide Scriptname From URL

Want to hide that pesky script name (e.g., `index.php`) from the URL?

### FrankenPHP

In [FrankenPHP](https://frankenphp.dev); the modern PHP app server. This behavior is enabled by default for `index.php`

### NGINX

With PHP already installed and configured you may add this to the server block of your configuration to make requests that don't match a file on your server to be redirected to `index.php`

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Apache

Make sure that mod_rewrite is installed on Apache. On a unix system you can just do `a2enmod rewrite`

This snippet in your .htaccess will ensure that all requests for files and folders that does not exists will be redirected to `index.php`

```apache
RewriteEngine on
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php?q=$1 [L,QSA]
```

## Performance

It's usually not the router that is the bottleneck of an application, hopefully, and while not primarily designed for performance, this router incorporates optimizations in key areas.

### Enable Route Cache

To optimize route lookups, this router uses a tree-based algorithm. However, regenerating the tree for every request slows down performance. To improve application boot times, you can enable route tree caching. Simply invoke the `fromCache()` method before defining any routes in your application.

The named argument `expire` can be set to an integer representing how many seconds till the cache gets invalidated and rebuilt again.

```php
Route2::fromCache(
    enabled: true,
    expire: false,
    filepath: 'Route2.cache.php'
);

// Define routes below...
```

### Benchmarks

This test was done using FrankenPHP and `wrk` on a Windows 11 machine running in WSL with an Intel® Core™ i5-12400 Processor from 2022.


| Benchmark | Routes| Average Latency | Requests Per Second
| --- | ----------- | - | - |
Worker Baseline | 1 |82.11ms | 62327.59
Worker | 178 (bitbucket) | 81.94ms | 57133.98
Classic Baseline | 1 | 104.75ms | 36100.91
Classic Cached | 178 (bitbucket) | 109.69ms |25992.43
Classic | 178 (bitbucket) | 31.35ms | 15652.60 

## License

This library is licensed under the **WTFPL-1.0**. Do whatever you want with it.
