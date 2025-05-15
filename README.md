# Route2 🛣️

A simple routing library for PHP web applications.

### Features

- **Route Parameters** 🧩  
    Define routes with required, optional, or wildcard parameters for flexible and expressive URL patterns.

- **Parameter Validation** 🛡️  
    Enforce and transform route parameters using regular expressions or custom logic for precise control.

- **Middleware Support** 🦺  
    Integrate middleware before and after route execution for granular request processing.

- **Route Grouping** 🗂️  
    Organize related routes with grouping, enabling shared attributes and a maintainable structure.

- **Dependency Free** 🪶  
    Lightweight and dependency-free, offering enhanced security, easier integration and maintainability.

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
  - [How to Register Middleware](#how-to-register-middleware)
- [Route Groups](#route-groups)
  - [Creating a Route Group](#creating-a-route-group)
  - [Groups Without a Prefix](#groups-without-a-prefix)
- [Dispatching](#dispatching)
  - [Accessing Your Routes](#accessing-your-routes)
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

Requires PHP 8.1 or newer

## Quick Start

Here's a basic getting started example:

```php
<?php

require __DIR__.'/../vendor/autoload.php';

use Wilaak\Http\Route2;

function hello($world) {
    echo "Hello, $world!";
}

Route2::get('/{world?}', 'hello');

Route2::dispatch();
```

### FrankenPHP Worker Mode

Boot your application once and keep it in memory by using [worker mode](https://frankenphp.dev/docs/worker/). This can dramatically increase performance.

```php
<?php

ignore_user_abort(true);

require __DIR__.'/../vendor/autoload.php';

use Wilaak\Http\Route2;

function hello($world) {
    echo "Hello, $world!";
}

Route2::get('/{world?}', 'test');

$handler = static function() {
    Route2::dispatch();
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
```

## Basic Routing

Define routes by specifying the HTTP method, the URI pattern, and the handler to execute when the route matches. Handlers can be function names, static methods, or class methods (with automatic instantiation):

```php
Route2::get('/greeting', 'function_name')
Route2::get('/greeting', 'StaticController::greeting');
Route2::get('/greeting', [ObjectController::class, 'greeting']);
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
Route2::match('get|post', '/', 'handler');
Route2::any('/', 'handler');
```

## Route Parameters

Sometimes you will need to capture segments of the URI within your route. For example, you may need to capture a user's ID from the URL. You may do so by defining route parameters:

> **Note:**  
> - Enclose parameters in curly braces within your route path, like `/{param}` or `/{param}/`.
> - Parameter names should use only letters, numbers, and underscores—no dashes or special characters.
>
> **Examples:**  
> - `/user-{id}` &nbsp;❌&nbsp; *(invalid)*  
> - `/user/{id}` &nbsp;✅&nbsp; *(valid)*

You can define as many route parameters as required by your route:

```php
function handler($post, $comment) {
    echo $post . ' ' . $comment
}

Route2::get('/posts/{post}/comments/{comment}', 'handler');
```

### Required Parameters

These parameters must be provided or the route is skipped.

```php
Route2::get('/user/{id}', 'handler');
```

### Optional Parameters

Specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name.

>**Note**: Must be the last parameter. Make sure to give the route's corresponding variable a default value:

```php
function handler($name = 'John') {
    echo $name;
}

Route2::get('/user/{name?}', 'handler');
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

>**Note**: Must be the last parameter. Make sure to give the route's corresponding variable a default value:

```php
function handler($any = null) {
    echo $any;
}

Route2::get('/somewhere/{any*}', 'handler');
```

### Parameter Expressions 

You can enforce specific formats for your route parameters by using the `expression()` method. This method accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a handler.

- **Regex**: Ensures the parameter matches the specified pattern. If not the route will be skipped.
- **Handler**: The handler should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

Routes added after this method will inherit the expressions.

```php
Route2::expression([
    // By specifying #^ ... $# you are telling the expression to use regex
    'id' => '#^[0-9]+$#'
    // Uses php function to verify that the value of id is numeric
    'id' => 'is_numeric',
    // Uses php function to transform the parameter value
    'name' => 'strtoupper'
]);
```

## Middleware

Middleware are like filters or layers that process HTTP requests before and after they reach your application's core logic. Think of them as checkpoints—each middleware can inspect, modify, or even halt a request. For example, an authentication middleware might redirect unauthenticated users to a login page, while letting authenticated users continue.

> **Important:** If a middleware handler returns `false`, the request will be halted and a 404 page will be displayed.

> **Note:** Middleware are only executed when a matching route is found.

### How to Register Middleware

You can easily add middleware to your routes using the `before()` and `after()` methods. These methods accept an array of middleware handlers.

Once middleware is registered, all routes defined afterwards will automatically inherit them.

```php
Route2::before([
    'your_middleware_handler',
    [YourMiddleware::class, 'handler']
]);

// Routes defined below will use the registered middleware
```

## Route Groups

Route groups make it easy to organize related routes and share common attributes—such as middleware or parameter expressions—across multiple routes. This helps keep your code DRY and maintainable. Groups can be nested, and nested groups automatically inherit attributes from their parent, much like variable scopes.

### Creating a Route Group

To group routes under a common prefix, use the `group()` method. All routes defined within the group will share the specified prefix and any attributes you assign:

```php
Route2::group('/admin', function () {
    // This middleware only affect routes within this group and its children
    Route2::before(['admin_middleware_handler']);

    Route2::get( '/dashboard', 'admin_dashboard_handler');
    Route2::post('/settings',  'admin_settings_handler');
});
```

### Groups Without a Prefix

You can also create a group without a prefix by omitting the first argument and using the `callback` named argument. This is useful for sharing route attributes without affecting the route path:

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

### Accessing Your Routes

The simplest way to access your routes is to put the file in your folder and run it.

When you visit a URL like `http://your.site/yourscript.php/your/route`, the router will automatically adjust the path to `/your/route`.

## Hide Script Name from URL

Want to remove the script name (like `index.php`) from your URLs for cleaner, more user-friendly links? Here’s how you can achieve this with different web servers:

### FrankenPHP

[FrankenPHP](https://frankenphp.dev), the modern PHP app server, hides `index.php` from URLs by default. No extra configuration is needed—just enjoy clean URLs out of the box!

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
RewriteRule ^(.*)$ index.php?q=$1 [L,QSA]
```

## License

This library is licensed under the **WTFPL-1.0**. Do whatever you want with it.
