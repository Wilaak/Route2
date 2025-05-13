# Route2 🛣️

A simple routing library for PHP web applications.

### Features

- **Dynamic Route Parameters** 🧩  
    Define routes with required, optional, or wildcard parameters for flexible and expressive URL patterns.

- **Advanced Parameter Validation** 🛡️  
    Enforce and transform route parameters using regular expressions or custom logic for precise control.

- **Comprehensive Middleware Support** 🦺  
    Integrate middleware before and after route execution for granular request processing and security.

- **Structured Route Grouping** 🗂️  
    Organize related routes with group functionality, enabling shared attributes and a maintainable structure.

- **Extensible Hook System** 🪝  
    Customize and extend routing behavior with hooks—ideal for logging, dependency injection, or PSR-7 compatibility.

- **High-Performance Routing** ⚡  
    Benefit from a tree-based algorithm and optional route caching for fast and efficient route resolution.

- **Lightweight and Dependency-Free** 🪶  
    Minimal, single-file design with no external dependencies, easy to integrate and maintain.

## Install

Install with composer:

    composer require wilaak/route2

Or simply include it in your project:

```php
require '/path/to/Route2.php'
```

Requires PHP 8.1 or newer

## Table of Contents

- [Quick Start](#quick-start)
  - [FrankenPHP Worker Mode](#frankenphp-worker-mode)
- [Basic Routing](#basic-routing)
  - [Available Methods](#available-methods)
  - [Multiple HTTP-verbs](#multiple-http-verbs)
- [Route Parameters](#route-parameters)
  - [Required Parameters](#required-parameters)
  - [Optional Parameters](#optional-parameters)
  - [Wildcard Parameters](#wildcard-parameters)
  - [Parameter Expressions](#parameter-expressions)
- [Middleware](#middleware)
  - [Registering Middleware](#registering-middleware)
- [Route Groups](#route-groups)
  - [Without prefix](#without-prefix)
- [Dispatching](#dispatching)
  - [Fallbacks](#fallbacks)
  - [Accessing Routes](#accessing-routes)
- [Hooks](#hooks)
  - [Registering Hooks](#registering-hooks)
  - [Available Hooks](#available-hooks)
- [Hide Scriptname From URL](#hide-scriptname-from-url)
  - [FrankenPHP](#frankenphp)
  - [NGINX](#nginx)
  - [Apache](#apache)
- [Performance](#performance)
  - [Enable Route Cache](#enable-route-cache)
  - [Benchmarks](#benchmarks)
- [License](#license)

## Quick Start

Here's a basic getting started example:

```php
<?php

require __DIR__.'/../vendor/autoload.php';

use Wilaak\Http\Route2;

Route2::get('/{world?}', function($world = 'World') {
    echo "Hello, $world!";
});

Route2::dispatch();
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
    Route2::dispatch();
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

Sometimes you need to register a route that responds to multiple HTTP-verbs. You can do so using the `match()` method. Or, you can even register a route that responds to all HTTP verbs using the `any()` method:

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

>**Note**: Parameters must be enclosed in forward slashes (e.g., `/{param}/`) and the names must not contain any special characters.

You can define as many route parameters as required by your route:

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

>**Note**: Must be the last parameter. Make sure to give the route's corresponding variable a default value:

```php
Route2::get('/user/{name?}', function (string $name = 'John') {
    echo $name;
});
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

>**Note**: Must be the last parameter. Make sure to give the route's corresponding variable a default value:

```php
Route2::get('/somewhere/{any*}', function ($any = 'Empty') {
    // Matches everything after the parameter
});
```

### Parameter Expressions 

You can enforce specific formats for your route parameters by using the `expression` named argument. This argument accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a closure.

- **Regex**: A regex string ensures the parameter matches the specified pattern. If not the route will be skipped.
- **Closure**: The closure should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

```php
Route2::get('/user/{id}', function ($id) {
    echo "User ID: $id";
}, expression: ['id' => '[0-9]+']);

Route2::get('/user/{id}', function ($id) {
    echo "User ID: $id";
}, expression: ['id' => is_numeric(...)]);

Route2::get('/echo/{message}', function($message) {
    echo $message;
}, expression: ['message' => strtoupper(...)]);
```

If you would like a route parameter to always be affected by a given expression, you may use the `expression()` method. Routes added after this method will inherit the expression.

```php
Route2::expression([
    'id' => is_numeric(...)
]);

Route2::get('/user/{id}', function ($id) {
    // Only executed if {id} is numeric...
});
```

## Middleware

Middleware inspect and filter HTTP requests entering your application. You can imagine them as a series of layers that your application has to go through before hitting the main part. For instance, authentication middleware can redirect unauthenticated users to a login screen, while allowing authenticated users to proceed.

>**Note**: Middleware only run if a route is found.

### Registering Middleware

You may register a middleware by using the `before()` and `after()` methods. Routes added after this method call will inherit the middleware. You may also assign a middleware to a specific route by using the named arguments `before` and `after`:

```php
// Runs before the route callback
Route2::before([
    your_middleware(...),
]);

// Runs after the route callback
Route2::after(function() {
    echo 'Terminating!';
});

// Runs before the route but after the inherited middleware
Route2::get('/', function() {
    // ...
}, before: fn() => print('I am also a middleware'));
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

The `dispatch()` method is responsible for handling incoming requests and matching them to your defined routes.

By default, `dispatch()` automatically detects the HTTP method and the relative URI from the current request, using `$_SERVER['REQUEST_METHOD']` and `$_SERVER['REQUEST_URI']` in combination with the `getRelativeRequestUri()` helper. For example, a request to `/folder/index.php/myroute` will be resolved as `/myroute`.

If you need more control, you can also call `dispatch()` with explicit HTTP method and URI arguments:

```php
Route2::dispatch('POST', '/custom/route');
```

This flexibility allows you to easily test routes or integrate with custom server environments.


### Fallbacks

> **Note:** Fallbacks are global—they do not depend on inheritance. You can define them anywhere, and they will always apply.

When no route matches the incoming request, you can use the `fallback()` method to handle these cases gracefully. This is useful for displaying custom 404 pages or JSON error responses.

Define a fallback handler that runs when no route matches:

```php
Route2::fallback(callback: function() {
    echo <<<HTML
        <h1>Page not found</h1>
    HTML;
});
```

You can scope a fallback to URIs that start with a specific prefix. This is especially useful for APIs or grouped routes. If used inside a group with a prefix, the group prefix is applied by default unless you specify another prefix.

```php
// Fallback for all /api routes
Route2::fallback('/api', function() {
    echo json_encode('page not found');
});

// Or, inside a group (inherits the group prefix)
Route2::group('/api', function() {
    Route2::fallback(callback: function() {
        echo json_encode('page not found');
    });
});
```

Fallbacks ensure your application responds predictably and user-friendly, even when a route is not found.

### Accessing Routes

The simplest way to access your routes is to put the file in your folder and run it.

For example if you request ```http://your.site/yourscript.php/your/route``` it will automatically adjust to `/your/route`.

## Hooks

Hooks allow you to execute custom logic at various points in the router's lifecycle. Use them to implement features like logging, dependency injection, or performance monitoring. By leveraging hooks, you gain fine-grained control and flexibility over your application's routing behavior.

### Registering Hooks

To register a callback for a specific event, you may use the `hook()` method:

```PHP
Route2::hook('eventName', function (...$args) {
    // Your custom logic here
});
```

### Available Hooks

#### `routeFound`
- **Description**: Triggered when a route is successfully matched.
- **Arguments**:
    - **`$requestMethod`** *(string)*: The HTTP method of the request.
    - **`$requestUri`** *(string)*: The URI of the request.
    - **`$params`** *(array)*: The parameters extracted from the route.

---

#### `invokeMiddleware`
- **Description**: Triggered before invoking a middleware.
- **Arguments**:
    - **`$middleware`** *(callable)*: The middleware to be invoked.

---

#### `invokeController`
- **Description**: Triggered before invoking the route's controller.
- **Arguments**:
    - **`$controller`** *(callable)*: The controller to be invoked.
    - **`$params`** *(array)*: The parameters to be passed to the controller.

---

#### `methodNotAllowed`
- **Description**: Triggered when a route is matched, but the HTTP method is not allowed.
- **Arguments**:
    - **`$requestMethod`** *(string)*: The HTTP method of the request.
    - **`$requestUri`** *(string)*: The URI of the request.
    - **`$allowedMethods`** *(array)*: The list of allowed HTTP methods for the route.

---

#### `routeNotFound`
- **Description**: Triggered when no route matches the request.
- **Arguments**:
    - **`$requestMethod`** *(string)*: The HTTP method of the request.
    - **`$requestUri`** *(string)*: The URI of the request.
    - **`$fallback`** *(callable)*: The fallback function to handle the request.

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

The router is and should rarely be the bottleneck of an application, hopefully. For most applications, the performance of this router will be more than sufficient.

### Enable Route Cache

To optimize route lookups, this router uses a tree-based algorithm. However, regenerating the tree for every request slows down performance. To improve application boot times, you can enable route tree caching. Simply invoke the `fromCache()` method before defining any routes in your application.

The named argument `expire` can be set to an integer representing how many seconds till the cache gets invalidated and rebuilt again.

>**Note**: Route caching is not necessary in worker mode since the route tree is retained in memory. You may still enable it if you find yourself restarting the workers often.

>**Caution**: Use caution when setting the `filepath`. The specified file may be overwritten or removed during the caching process, so ensure it does not conflict with other important files.

```php
Route2::fromCache(
    enabled: true,
    expire: false,
    filepath: 'Route2.cache.php'
);

// Define routes below...
```

### Benchmarks

This test was done using FrankenPHP and `wrk` on a Windows 11 machine in WSL with an Intel Core i5-12400 Processor.


| Benchmark | Routes| Average Latency | Requests Per Second
| --- | ----------- | - | - |
Worker Baseline | 1 |82.11ms | 62327.59
Worker | 178 (bitbucket) | 81.94ms | 57133.98
Classic Baseline | 1 | 104.75ms | 36100.91
Classic Cached | 178 (bitbucket) | 109.69ms |25992.43
Classic | 178 (bitbucket) | 31.35ms | 15652.60 

## License

This library is licensed under the **WTFPL-1.0**. Do whatever you want with it.
