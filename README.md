# Route2 🛣️

A simple routing library for PHP web applications.

### Features

- **Flexible URLs** 🧩  
    Support for required, optional, and wildcard segments.

- **Parameter Validation** 🛡️  
    Filter or transform parameters with regex or custom logic.

- **Middleware** 🦺  
    Add logic before or after route handlers.

- **Route Groups** 🗂️  
    Organize and share attributes across routes.

- **High Performance** ⚡  
    Efficient tree-based route matching that is easy to cache.

- **Zero Dependencies** 🪶  
    Single-file, no-frills dependency-free routing solution.

## Table of Contents

- [Install](#install)
- [Quick Start](#quick-start)
    - [FrankenPHP Worker Mode](#frankenphp-worker-mode)
- [What is a Handler?](#what-is-a-handler)
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
- [Performance](#performance)
    - [How to Cache Your Routes](#how-to-cache-your-routes)
    - [Benchmarks](#benchmarks)
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

## What is a Handler?

You'll see the term **handler** mentioned throughout this document. But what does it mean? In Route2, a handler is simply a reference to the function or method that will be executed.

You can define handlers in several ways:

- **Function Name**  
    Use a string with the name of a global function:  
    `'greeting'`

- **Static Class Method**  
    Use a string in the format:  
    `'StaticController::greeting'`

- **Instance Method (Array Syntax)**  
    Use an array with the class and method name:  
    `[ObjectController::class, 'greeting']`  
    or  
    `['ObjectController', 'greeting']`  
    In this case it will instantiate the class before calling the method.

## Basic Routing

Define routes by specifying the HTTP method, the URI pattern, and the [handler](#what-is-a-handler) to execute when the route matches.

```php
Route2::get('/greeting', 'greeting');
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
// Specify your own methods
Route2::match('get|post', '/', 'handler');
// For GET and POST methods
Route2::form('/', 'handler')
// Matches all HTTP methods
Route2::any('/', 'handler');
```

## Route Parameters

Often, you'll want to capture parts of the URL as variables—such as a user's ID or a post slug. Route parameters make this easy and flexible.

> **How to use parameters:**  
> - Wrap parameter names in curly braces: `/{param}` or `/{param}/`.
> - Use only letters, numbers, and underscores for parameter names—avoid dashes or special characters.
> - If a parameter isn't the last segment, it must be separated by slashes (e.g., `/posts/{post}/comments/{comment}`), not embedded within another segment.
>   - `/posts/{post}/comments/{comment}` &nbsp;✅&nbsp; *(valid)*  
>   - `/posts/{post}-comments/{comment}` &nbsp;❌&nbsp; *(invalid)*

---

> **Notice:**  
> Route2 always prefers exact matches over parameterized routes.  
> ```php
> Route2::get('/somewhere/{any}', 'handler');
> Route2::get('/somewhere/someplace', 'handler');
> ```
> If you visit `/somewhere/someplace`, the exact route (`/somewhere/someplace`) will be matched first, not the parameterized one.


### Required Parameters

These parameters must be provided or the route is skipped.

```php
Route2::get('/user/{id}', 'handler');
```

You can define as many required parameters as required by your route:

```php
function handler($post, $comment) {
    echo $post . ' ' . $comment
}

Route2::get('/posts/{post}/comments/{comment}', 'handler');
```

### Optional Parameters

Specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name.

>**Note**: Should be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($name = 'John') {
    echo $name;
}

Route2::get('/user/{name?}', 'handler');
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

>**Note** Should be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($any = null) {
    echo $any;
}

Route2::get('/somewhere/{any*}', 'handler');
```

### Parameter Expressions 

You can enforce specific formats for your route parameters by using the `expression()` method. This method accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a [handler](#what-is-a-handler).

- **Regex**: Ensures the parameter matches the specified pattern. If not, the route will be skipped.
- **Handler**: The [handler](#what-is-a-handler) receives the parameter value as its argument. It should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

Routes added after this method will inherit the expressions.

```php
Route2::expression([
    // By specifying #^ ... $# you are telling the expression to use regex
    'id' => '#^[0-9]+$#',
    // Uses handler to verify that the value of id is numeric
    'id' => 'is_numeric',
    // Uses handler to transform the parameter value
    'name' => 'strtoupper'
]);
```

## Middleware

Middlewares are like filters or layers that process HTTP requests before and after they reach your application's core logic. Think of them as checkpoints—each middleware can inspect, modify, or even halt a request. For example, an authentication middleware might redirect unauthenticated users to a login page, while letting authenticated users continue.

> **Note:** Middleware are only executed when a matching route is found.

### How to Register Middleware

You can add middleware to your routes using the `before()` and `after()` methods. These methods accept an array of middleware [handlers](#what-is-a-handler).

Once a middleware is registered, all routes defined afterwards will inherit them.

```php
Route2::before([
    'your_middleware_handler',
    [YourMiddleware::class, 'handler']
]);

// Routes defined below will use the middleware
```

## Route Groups

Route groups make it easy to organize related routes and share common attributes—such as middleware or parameter expressions—across multiple routes. Groups can be nested, and nested groups automatically inherit attributes from their parent, much like variable scopes.

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

By default, `dispatch()` automatically detects the HTTP method and the relative URI from the current request, using `$_SERVER['REQUEST_METHOD']` and `$_SERVER['REQUEST_URI']` from the `getRelativeRequestUri()` helper. For example, a request to `/folder/index.php/myroute` will be resolved as `/myroute`.

If you need more control, you can also call `dispatch()` with explicit HTTP method and URI arguments:

```php
Route2::dispatch('POST', '/custom/route');
```

### Accessing Your Routes

The simplest way to access your routes is to put the file in your folder and run it.

When you visit a URL like `http://your.site/yourscript.php/your/route`, the router will automatically adjust the path to `/your/route`.

## Performance

It's usually not the router that is the bottleneck of an application, hopefully.

### How to Cache Your Routes

To maximize performance, Route2 uses a tree-based algorithm for fast route lookups. However, rebuilding this tree on every request can slow down your application’s startup time. The solution? Cache the generated route tree!

The compiled route tree in Route2 is just a collection of strings and arrays, you can easily serialize them for reuse. Here’s how you can implement a basic route cache:

```php
<?php

require __DIR__.'/../vendor/autoload.php';

use Wilaak\Http\Route2;

function hello($world = 'World') {
    echo "Hello, $world!";
}

function build_routes() {
    Route2::get('/{world?}', 'hello');
}

$cacheFile = 'cache.php';
if (file_exists($cacheFile)) {
    // Load the cached route tree for instant boot
    Route2::$routeTree = include $cacheFile;
} else {
    // Build routes and cache the tree for future requests
    build_routes();
    file_put_contents(
        $cacheFile,
        '<?php return ' . var_export(Route2::$routeTree, true) . ';'
    );
}

Route2::dispatch();
```

By exporting the route tree to a PHP file, you let PHP’s OPcache handle the heavy lifting—making route loading nearly instantaneous on subsequent requests. This approach is both simple and highly efficient.

### Benchmarks

Here’s a quick look at Route2’s performance in different modes.

- **Long Route:** The most complex route with multiple segments and parameters  
- **Short Route:** A simple static route  
- Benchmarks were run on FrankenPHP v1.5.0, PHP 8.4.6, Ubuntu Linux (WSL on Windows 11), Intel Core i5-12400, using `wrk` on the same machine.

| **Mode**                    | **Routes** | **Long Route (req/s)** | **Short Route (req/s)** |
|-----------------------------|:----------:|:------------------------:|:--------------------------:|
| Route2 Worker Baseline  |     1      |           N/A            |        62,189 rps          |
| Route2 Worker           |   178      |        49,991 rps        |        61,515 rps          |
| Route2 Classic (cached) |   178      |        39,010 rps        |        43,250 rps          |
| Route2 Classic Baseline |     1      |           N/A            |        39,654 rps          |
| Route2 Classic          |   178      |        13,900 rps        |        14,045 rps          |

For details on how to run these benchmarks yourself, see the `benchmark` folder.

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
