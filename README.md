# Route2 🛣️

A simple routing library for PHP web services.

### Features

- 🧭 **Flexible URLs** Support for required, optional, and wildcard segments.
- 🛡️ **Parameter Validation** Filter and transform parameters with regex or custom logic.
- 🧩 **Middleware** Add logic before and after route handlers.
- 🗂️ **Route Groups** Organize and share attributes across routes.
- 🪝 **Handler Hooks** Intercept how route handlers are executed. (Integrate with DI-Containers)
- ⚡ **Lightweight** Single-file, dependency-free and ~160 lines of code.

## Table of Contents

- [Install](#install)
- [Quick Start](#quick-start)
- [How does it work?](#how-does-it-work)
- [What is a Handler?](#what-is-a-handler)
    - [Hooking Into the Handler](#hooking-into-the-handler)
- [Basic Routing](#basic-routing)
    - [Available Methods](#available-methods)
    - [Multiple HTTP-verbs](#multiple-http-verbs)
- [Route Parameters](#route-parameters)
    - [Required Parameters](#required-parameters)
    - [Optional Parameters](#optional-parameters)
    - [Wildcard Parameters](#wildcard-parameters)
    - [Parameter Expressions](#parameter-expressions)
- [Middleware](#middleware)
- [Route Groups](#route-groups)
- [Hide Script Name from URL](#hide-script-name-from-url)
    - [FrankenPHP](#frankenphp)
    - [NGINX](#nginx)
    - [Apache](#apache)
- [License](#license)

## Install

Install with composer:

    composer require wilaak/route2

Or simply include it in your project:

```PHP
require '/path/to/Route2.php'
```

Requires PHP 8.3 or newer

## Quick Start

Here's a basic getting started example:

```php
<?php

require '../vendor/autoload.php';

use Wilaak\Http\Route2;

// Simple JSON response helper
function send_json($data, int $status = 200): void {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
}

$router = new Route2();

// Example API group
$router->group('/api', function ($router) {
    $router->get('/users', function () {
        send_json(['users' => ['Alice', 'Bob']]);
    });
    $router->post('/login', function () {
        send_json(['message' => 'Logged in!']);
    });
});

// Simple HTML routes
$router->get('/', function () {
    echo "<h1>Home</h1>";
});
$router->get('/about', function () {
    echo "<h1>About</h1>";
});

// Fallback for unmatched routes
if ($router->allowedMethods) {
    header('Allow: ' . implode(', ', $router->allowedMethods));
    http_response_code(405);
    echo "<h1>Method Not Allowed</h1>";
} else {
    http_response_code(404);
    echo "<h1>Not Found</h1>";
}

```

## How does it work?

It follows a very dumb and simple approach: each request is checked against your routes in the order you defined them. As soon as it finds a match, it runs the handler and terminates, so only the first matching route is processed.

Could it be more efficient? Sure. But for most projects, this approach is more than fast enough—it's unlikely to ever be your actual bottleneck! And if you absolutely need to squeeze out some extra performance you can utilize prefixed [Route Groups](#route-groups).

## What is a Handler?

You'll see the term **handler** mentioned throughout this document. But what does it mean? In Route2, a handler is simply a reference to the function or method that will be executed.

You can define handlers in a variety of ways:

- **Anonymous Function (Closure)**  
    Pass an inline function directly:
    ```php
    function () { echo 'Greetings!'; }
    ```

- **Named Function**  
    Reference a global function by its name as a string:
    ```php
    'greeting'
    ```

- **Static Class Method**  
    Use the `ClassName::method` string format to call a static method:
    ```php
    'StaticController::greeting'
    ```

- **Instance Method (Array Syntax)**  
    Provide an array with the class name and method; the class will be instantiated for you:
    ```php
    [ObjectController::class, 'greeting']
    ```

### Hooking Into the Handler

You can take control over how handlers are run by setting a handler hook. This lets you plug in things like dependency injection, logging, or any custom logic before your route handler is called.

To do this, use the `setHandlerHook()` method. The hook gives you access to the handler and its parameters, so you can decide exactly how the handler should be executed:

```php
$router->setHandlerHook(function(mixed $handler, array $params): callable {
    // Add your custom logic here (e.g., resolve dependencies, log calls, etc.)
    return is_array($handler)
        ? fn() => (new $handler[0])->{$handler[1]}(...$params)
        : fn() => $handler(...$params);
});
```

Routes added afterwards will inherit this new way of executing handlers.

## Basic Routing

Define routes by specifying the HTTP method, the URI pattern, and the [handler](#what-is-a-handler) to execute when the route matches.

```php
$router->get('/greeting', function() {
    echo 'Greetings!'
});
```

### Available Methods

The router allows you to register routes that respond to any HTTP verb:

```php
$router->get($uri, $handler);
$router->post($uri, $handler);
$router->put($uri, $handler);
$router->patch($uri, $handler);
$router->delete($uri, $handler);
$router->options($uri, $handler);
```

### Multiple HTTP-verbs

Sometimes you need to register a route that responds to multiple HTTP-verbs. You can do so using the `match()` method. Or, you can even register a route that responds to all HTTP verbs using the `any()` method:

```php
// Specify your own methods
$router->match(['GET', 'POST'], '/', 'handler');
// For GET and POST methods
$router->form('/', 'handler')
// Matches all HTTP methods
$router->any('/', 'handler');
```

## Route Parameters

Route parameters let you capture parts of the URL.

### Required Parameters

These parameters must be provided or the route is skipped.

```php
$router->get('/user/{id}', 'handler');
```

You can define as many required parameters as required by your route:

```php
function handler($post, $comment) {
    echo 'Showing post:' . $post . ' and comment: ' . $comment;
}

$router->get('/posts/{post}/comments/{comment}', 'handler');
```

### Optional Parameters

Specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name.

>**Note**: Must be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($name = 'John') {
    echo $name;
}

$router->get('/user/{name?}', 'handler');
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

>**Note**: Must be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($any = 'Empty') {
    echo $any;
}

$router->get('/somewhere/{any*}', 'handler');
```

### Parameter Expressions 

You can enforce specific formats for your route parameters by using the `expression()` method. This method accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a [handler](#what-is-a-handler).

- **Regex**: Ensures the parameter matches the specified pattern. If not, the route will be skipped.
- **Handler**: The [handler](#what-is-a-handler) receives the parameter value as its argument. It should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

Routes added after this method will inherit the expressions.

```php
$router->expression([
    // By specifying #^ ... $# you are telling the expression to use regex
    'id' => '#^[0-9]+$#',
    // Uses handler to verify that the value of id is numeric
    'id' => 'is_numeric',
    // Uses handler to transform the parameter value
    'name' => 'strtoupper'
]);
```

## Middleware

Middlewares are like filters or layers that process HTTP requests before and after they reach your application's core logic. Think of them as checkpoints—each middleware can inspect, modify, or halt a request. For example, an authentication middleware might redirect unauthenticated users to a login page, while letting authenticated users continue.

> **Note**: Middleware are only executed when a matching route is found. If a middleware handler returns `false`, the script will terminate.

You can add middleware to your routes using the `before()` and `after()` methods. These methods accept a [handler](#what-is-a-handler).

Once a middleware is registered, all routes defined afterwards will inherit them.

```php
// Register middleware that runs before the handler
$router->before('your_middleware_handler');
// Register middleware that runs after the handler
$router->after('your_middleware_handler');
// Routes defined below will inherit the middleware
```

## Route Groups

Route groups make it easy to organize related routes and share common attributes. Groups can be nested, and nested groups automatically inherit attributes from their parent, much like variable scopes.

> **Tip**: For applications with many routes, using prefixed groups can improve performance as it will skip evaluating all routes in the group if the request URI doesn't start with the prefix.

To group routes under a common prefix, use the `group()` method. All routes defined within the group will share the specified prefix and any attributes you assign:

```php
$router->group('/admin', function ($router) {
    // This middleware only affect routes within this group and any nested groups
    $router->before([AdminMiddleware::class, 'handle']);
    // It may be ugly, but by passing an empty string it will only use the prefix (e.g /admin)
    $router->get('',           [AdminController::class, 'index']);
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->post('/settings', [AdminController::class, 'settings']);
});
```

You can also create a group without a prefix by omitting the first argument and using the `callback` named argument. This is useful for sharing route attributes without affecting the route path:

```php
$router->group(callback: function ($router) {
    // ...
});
```

## Hide Script Name from URL

Want to remove the script name (like `index.php`) from your URLs for cleaner, more user-friendly links? Here’s how you can achieve this with different web servers:

### FrankenPHP

[FrankenPHP](https://frankenphp.dev), the modern PHP app server, hides `index.php` from URLs by default. No extra configuration is needed.

### NGINX

To hide `index.php` in NGINX, add the following to your server block. This configuration ensures that requests not matching an existing file or directory are routed to `index.php`:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Apache

First, ensure `mod_rewrite` is enabled (on Unix: `a2enmod rewrite`). Then, add this snippet to your `.htaccess` file. It will redirect all requests for non-existent files or directories to `index.php`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php/$1 [L]
```

## License

This library is licensed under the **WTFPL-1.0**. Do whatever you want with it.
