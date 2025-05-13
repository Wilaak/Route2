# Route2 🛣️

A simple routing library for PHP web applications.

### Features:
- **Parameters**: Flexible routing with required, optional and wildcard parameters.
- **Constraints**: Use regex or custom functions to validate and intercept parameters.
- **Middleware**: Execute logic before and after route handling.
- **Grouping**: Organize routes with shared functionality for cleaner code.
- **Hooks**: Extend routing functionality for integrating with DI containers, metrics and more.
- **Lightweight**: A single-file, no-frills, dependency-free routing solution.

## Install

Install with composer:

    composer require wilaak/route2

Or simply include it in your project:

```php
require '/path/to/Route2.php'
```

Requires PHP 8.1 or newer

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

## Usage

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

>**Note**: Parameters must be enclosed in forward slashes (e.g., `/{param}/`) and may not contain any special characters.

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

### Parameter Constraints

You can enforce specific formats for your route parameters by using the `expression` named argument. This argument accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a function.

- **Regex**: Ensures the parameter matches the specified pattern. If not the route will be skipped.
- **Function**: The function should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

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

If you would like a route parameter to always be constrained by a given expression, you may use the `expression()` method. Routes added after this method will inherit the expression constraints.

>**Note**: Understanding how inheritance operates is essential for grasping the router's behavior and functionality.

```php
Route2::expression([
    'id' => is_numeric(...)
]);

Route2::get('/user/{id}', function ($id) {
    // Only executed if {id} is numeric...
});
```

## Middleware

Middleware inspect and filter HTTP requests entering your application. You can imagine them as a series of functions that your application has to go through before reaching the main function. For instance, authentication middleware can redirect unauthenticated users to a login screen, while allowing authenticated users to proceed. Middleware can also handle tasks like logging incoming requests.

>**Note**: Middleware only run if a route is found.

### Registering Middleware

You may register middleware by using the `before()` and `after()` methods. Routes added after this method call will inherit the middleware. You may also assign a middleware to a specific route by using the named argument `before` and `after`:

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

A URI is dispatched by calling the `dispatch()` method. This method accepts an HTTP method and a URI. If none are provided it uses the `$_SERVER['REQUEST_METHOD']` and internal `getRelativeRequestUri()` method which returns the relative URI of the current HTTP request. (e.g., `/folder/index.php/myroute` → `/myroute`)

```php
Route2::dispatch();
```

### Fallbacks

>**Note**: Fallbacks do not depend on inheritance, meaning that you may the define them anywhere and they will still apply.

Using the `fallback()` method, you may define a function that will be executed when no other route matches the incoming request.

```php
Route2::fallback(callback: function() {
    echo <<<HTML
        <h1>Page not found</h1>
    HTML;
});
```

You can define a fallback with an optional prefix, ensuring it only triggers when the requested URI begins with the specified prefix. If used within a prefixed group, the group prefix will be applied by default unless another prefix is explicitly provided.

```php
Route2::fallback('/api', function() {
    echo json_encode('page not found');
});

// This also works...
Route2::group('/api', function() {
    Route2::fallback(callback: function() {
        echo json_encode('page not found');
    });
});
```

### Accessing Routes

The simplest way to access your routes is to put the file in your folder and run it.

For example if you request ```http://your.site/yourscript.php/your/route``` it will automatically adjust to `/your/route`.

## Hooks

This router provides an event system that allows you to hook into various stages of the router's lifecycle. Examples of use include logging, dependency injection, or performance monitoring.

### Registering Hooks

To register a callback for a specific event, use the `hook()` method:

```PHP
<?php
Route2::hook('eventName', function (...$args) {
    // Your custom logic here
});
```

### Available Hooks

1. **`onDispatchStart`**
   - **Description**: Triggered at the start of the dispatch process.
   - **Arguments**:
     - `$requestMethod` (string): The HTTP method of the request (e.g., `GET`, `POST`).
     - `$requestUri` (string): The URI of the request.

2. **`onRouteMatch`**
   - **Description**: Triggered when a route is successfully matched.
   - **Arguments**:
     - `$requestMethod` (string): The HTTP method of the request.
     - `$requestUri` (string): The URI of the request.
     - `$params` (array): The parameters extracted from the route.

3. **`onMiddlewareInvoke`**
   - **Description**: Triggered before invoking a middleware.
   - **Arguments**:
     - `$middleware` (callable): The middleware to be invoked.

4. **`onControllerInvoke`**
   - **Description**: Triggered before invoking the route's controller.
   - **Arguments**:
     - `$controller` (callable): The controller to be invoked.
     - `$params` (array): The parameters to be passed to the controller.

5. **`onMethodNotAllowed`**
   - **Description**: Triggered when a route is matched, but the HTTP method is not allowed.
   - **Arguments**:
     - `$requestMethod` (string): The HTTP method of the request.
     - `$requestUri` (string): The URI of the request.
     - `$allowedMethods` (array): The list of allowed HTTP methods for the route.

6. **`onNotFound`**
   - **Description**: Triggered when no route matches the request.
   - **Arguments**:
     - `$requestMethod` (string): The HTTP method of the request.
     - `$requestUri` (string): The URI of the request.
     - `$fallback` (callable): The fallback function to handle the request.

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

The router should rarely be the bottleneck of an application, hopefully. For most applications, the performance of this router will be more than sufficient. However, if your project demands extreme performance, you might want to explore alternatives like [FastRoute](https://github.com/nikic/FastRoute).

### Enable Route Cache

To optimize route lookups, this router uses a tree-based algorithm. However, regenerating the tree for every request slows down performance. To improve application boot times, you can enable route tree caching. Simply invoke the `fromCache()` method before defining any routes in your application.

The named argument `expire` can be set to an integer representing how many seconds till the cache gets invalidated and rebuilt again.

>**Caution**: Be careful when specifying the `filepath` as the file may be overwritten or deleted during the caching process.

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
