# Route2 🛣️

A simple routing library for PHP web applications.

### Features:
- 🌟 **Parameters**: Flexible routing with required, optional and wildcard parameters.
- 🔍 **Constraints**: Use regex or custom functions for parameter constraints.
- 🛡️ **Middleware**: Execute logic before and after route handling.
- 🗂️ **Grouping**: Organize routes with shared functionality for cleaner code.
- 🪶 **Lightweight**: A single-file, no-frills dependency-free routing solution.

## Install

Install with composer:

    composer require wilaak/route2

Or simply include it in your project:

```php
require '/path/to/Route2.php'
```

Requires PHP 8.1 or newer

---

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
- [Troubleshooting](#troubleshooting)
  - [Dispatching](#dispatching)
  - [Accessing your routes](#accessing-your-routes)
- [Hide scriptname from URL](#hide-scriptname-from-url)
  - [FrankenPHP](#frankenphp)
  - [NGINX](#nginx)
  - [Apache](#apache)
- [Performance](#performance)
  - [Benchmark](#benchmark)
- [License](#license)

## Usage

Here's a basic getting started example:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Wilaak\Http\Route2;

Route2::get('/{world?}', function($world = 'World') {
    echo "Hello, $world!";
});

if (Route2::dispatch()) return;
http_response_code(404);
echo '404 Not Found';
```

### FrankenPHP Worker Mode

Boot your application once and keep it in memory by using [worker mode](https://frankenphp.dev/docs/worker/). This dramatically increases performance.

```php
<?php

ignore_user_abort(true);

require __DIR__.'/vendor/autoload.php';

use Wilaak\Http\Route2;

Route2::get('/{world?}', function($world = 'World') {
    echo "Hello, $world!";
});

$handler = static function() {
    if (Route2::dispatch()) return;
    http_response_code(404);
    echo '404 Not Found';
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

Sometimes you may need to register a route that responds to multiple HTTP-verbs. You may do so using the match method. Or, you may even register a route that responds to all HTTP verbs using the any method:

```php
Route2::match('get|post', '/', function() {
    // matches any method you like
});

Route2::forms('/', function() {
    // matches GET and POST methods
});

Route2::any('/', function() {
    // matches any HTTP method
});
```


## Route Parameters

Sometimes you will need to capture segments of the URI within your route. For example, you may need to capture a user's ID from the URL. You may do so by defining route parameters:

**Note**: The parameters injected into the controller will always be of type `string`.

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

Specify a route parameter that may not always be present in the URI. You may do so by placing a ? mark after the parameter name.

**Note**: Make sure to give the route's corresponding variable a default value:

```php
Route2::get('/user/{name?}', function (string $name = 'John') {
    echo $name;
});
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a * after the parameter name.

**Note**: Make sure to give the route's corresponding variable a default value:

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

If you would like a route parameter to always be constrained by a given expression, you may use the `expression` method. Routes added after this method will inherit the expression constraints.

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

**Note**: Middleware only run if a route is found.

### Registering Middleware

You may register middleware by using the `before` and `after` methods. Routes added after this method call will inherit the middleware. You may also assign a middleware to a specific route by using the named argument `middleware`:

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

## Troubleshooting

### Dispatching

When adding routes they are not going to be executed. To perform the routing call the `dispatch` method.

```php
// Dispatch the request
if (!Route2::dispatch()) {
    http_response_code(404);
    echo "404 Not Found";
}
```

### Accessing your routes

The simplest way to access your routes is to put the file in your folder and run it.

For example if you request ```http://your.site/yourscript.php/your/route``` it will automatically adjust to `/your/route`.

## Hide scriptname from URL

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

This router is not the fastest especially when using **Classic** PHP modes as it has to compile the routing tree for each request. If performance is crucial consider something else.

### Benchmark

Here is a test running against 178 routes. See `benchmark/routes.php`. The baselines are doing no routing and responding immediately.

**Note**: This is running on year 2014 level desktop shared hardware. (`Xeon E3-1226 v3`)

```
+------------------------+-----------+------------+
| Benchmark              | Latency   | Per Second |
+------------------------+-----------+------------+
| Baseline\Worker        | 19.94ms   | 20765.94   |
| Route2\Worker          | 22.65ms   | 18092.66   |
| Baseline\Classic       | 177.44ms  | 7731.38    | 
| Route2\Classic         | 114.87ms  | 3400.63    | 
+------------------------+-----------+------------+
```

Test was done using `wrk`on the same machine:

    wrk -t8 -c400 -d5s http://127.0.0.1:8080


## License

This library is licensed under the **WTFPL-1.0**. Do whatever you want with it.
