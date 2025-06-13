# Route2

A fast and simple autowiring request router for PHP web services

## Table of Contents

- [Install](#install)
- [Quick Start](#quick-start)
    - [Classic Mode](#classic-mode)
    - [FrankenPHP Worker Mode](#frankenphp-worker-mode)
- [What is a Handler?](#what-is-a-handler)
- [Autowiring](#autowiring)
    - [Usage Example](#usage-example)
- [Basic Routing](#basic-routing)
    - [Available Methods](#available-methods)
    - [Multiple HTTP-verbs](#multiple-http-verbs)
- [Route Parameters](#route-parameters)
    - [Required Parameters](#required-parameters)
    - [Optional Parameters](#optional-parameters)
    - [Wildcard Parameters](#wildcard-parameters)
    - [Parameter Rules](#parameter-rules)
- [Middleware](#middleware)
- [Route Groups](#route-groups)
- [Named Routes](#named-routes)
    - [Defining Named Routes](#defining-named-routes)
    - [Generating URLs](#generating-urls)
- [Debugging](#debugging)
    - [Listing All Routes](#listing-all-routes)
    - [Dispatching Routes](#dispatching-routes)
    - [Dispatch Return Codes](#dispatch-return-codes)
    - [Getting the Latest Dispatched Route](#getting-the-latest-dispatched-route)
- [Clean URLs: Removing `index.php` from Your Links](#clean-urls-removing-indexphp-from-your-links)
    - [FrankenPHP](#frankenphp)
    - [NGINX](#nginx)
    - [Apache](#apache)
- [Custom Error Pages](#custom-error-pages)
    - [Custom 404 Handler](#custom-404-handler)
    - [Custom 405 Handler](#custom-405-handler)
- [Performance](#performance)
    - [How To Cache Routes](#how-to-cache-routes)
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

Heres are some examples to get you up and running quickly.

### Classic Mode

```PHP
<?php
// public/index.php

require __DIR__ . '/../vendor/autoload.php';

$router = new Wilaak\Http\Route2();

$router->get('/{world?}', function ($world = 'World') {
    echo "Hello, {$world}!";
});

$router->dispatch();
```

### FrankenPHP Worker Mode

Boot your application once and keep serving from memory:

```PHP
<?php
// public/index.php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

// Boot your app
require __DIR__.'/../vendor/autoload.php';

$router = new Wilaak\Http\Route2();

$router->get('/{world?}', function ($world = 'World') {
    echo "Hello, {$world}!";
});

// Handler outside the loop for better performance (doing less work)
$handler = static function () use ($router) {
    // Called when a request is received,
    // superglobals, php://input and the like are reset
    $router->dispatch();
};

$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);
for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    $keepRunning = \frankenphp_handle_request($handler);

    // Do something after sending the HTTP response
    $myApp->terminate();

    // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
    gc_collect_cycles();

    if (!$keepRunning) break;
}
```

## What is a Handler?

A handler is simply a reference to the function or method that will be executed.

You can define handlers in multiple ways:

- **Named Function**  
    Reference a global function by its name as a string:
    ```php
    'controller\greeting'
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

- **Anonymous Function (Closure)**  
    > **Note:**  
    > Using anonymous functions is **not going to work** if you want to cache the routes as they cannot be serialized

    You can use an anonymous function directly as a handler using the standard `function` syntax:
    ```php
    function () {
        echo 'Greetings!';
    }
    ```
    Or, using the short `function(...)` syntax (PHP 8.1+):
    ```php
    greeting(...)
    // Or
    StaticController::greeting(...)
    ```
    Or, using arrow function syntax (PHP 7.4+):
    ```php
    fn() => print('Greeitings!');
    ```

## Autowiring

By providing a PSR-11 compatible dependency injection container, the router can automatically resolve and inject type-hinted dependencies into any [handler](#what-is-a-handler).

> **Note:**  
> Dependency injections must occur before any other [handler](#what-is-a-handler) arguments.
>  
> **Example:**  
> ```php
> // Correct: Dependency-injected argument ($db) comes before parameter ($id)
> $router->get('/user/{id}', function (DatabaseService $db, string $id) { ... });
> 
> // Incorrect: Parameter ($id) comes before dependency-injected argument ($db)
> $router->get('/user/{id}', function (string $id, DatabaseService $db) { ... });
> ```

### Usage Example

The router accepts the container in it's constructor. Here we are using [PicoDI](https://github.com/Wilaak/PicoDI).

```php
class DatabaseService {
    public function __construct(
        private PDO $pdo
    ) {}
}

$config = [
    DatabaseService::class => [
        'pdo' => fn() => new PDO('sqlite::memory:')
    ],
];

$container = new Wilaak\PicoDI\ServiceContainer($config);

$router = new Wilaak\Http\Route2(
    container: $container 
);

$router->get('/', function (DatabaseService $db) {
    echo "Index page with database connection!";
    var_dump($db);
});

$router->dispatch();
```

## Basic Routing

Register routes by specifying the HTTP method, the URI pattern, and the [handler](#what-is-a-handler) to execute when the route matches.

```php
$router->get('/greeting', 'handler');
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
$router->addRoute(['GET', 'POST'], '/', 'handler');
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
$router->get('/posts/{post}/comments/{comment}', function ($post, $comment) {
    echo "Showing post: {$post} and comment: {$comment}";
});
```

### Optional Parameters

Specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name.

> **Note:**  
> Must be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($name = 'John') {
    echo $name;
}

$router->get('/user/{name?}', 'handler');
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

> **Note:**  
> Must be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($any = 'Empty') {
    echo $any;
}

$router->get('/somewhere/{any*}', 'handler');
```

### Parameter Rules

You can enforce specific formats for your route parameters by using the `addParameterRules()` method. This method accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a [handler](#what-is-a-handler).

> **Note:**  
> Avoid making rule handlers overly complex. Since middlewares are **not** executed before parameter rules are applied, any logic such as rate limiting or authentication must be handled within the rule handler itself. Keeping rule handlers simple and focused on validation or transformation is recommended for maintainability.

- **Regex**: Ensures the parameter matches the specified pattern. If not, the route will be skipped.
- **Handler**: The [handler](#what-is-a-handler) receives the parameter value as a positional argument. It should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

Routes registered after this method will inherit the rules.

```php
$router->addParameterRules([
    // By specifying #^ ... $# you are telling the expression to use regex
    'id' => '#^[0-9]+$#',
    // A handler to verify that the value of id is numeric
    'id' => 'is_numeric',
    // A handler to transform the parameter value
    'name' => 'strtoupper',
    // A handler to transform the parameter value using autowiring
    'customer' => function (CustomerRepository $repo, $customerId): Customer|false {
        return $repo->find($customerId) ?: false;
    }
]);
```

## Middleware

A middleware is a [handler](#what-is-a-handler) that executes before route handlers. You can imagine them as a series of layers your request has to pass through before reaching your route handler. For example, you might use middleware to check if a user is authenticated, log request details, or modify the response before sending it to the client.

> **Note:** If a middleware handler returns `false` further processing will be halted.

Once a middleware is registered, all routes registered afterwards will inherit them.

```php
$router->addMiddleware('yourCustomMiddlewareFunction');
```

Basic authentication example:

```php
function basicAuthMiddleware() {
    $user = 'admin';
    $pass = 'secret';

    if (
        !isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) ||
        $_SERVER['PHP_AUTH_USER'] !== $user ||
        $_SERVER['PHP_AUTH_PW'] !== $pass
    ) {
        header('WWW-Authenticate: Basic realm="Protected Area"');
        http_response_code(401);
        echo 'Unauthorized';
        return false; // Stop further execution
    }
}

// Routes registered aftewards inherit this middleware
$router->addMiddleware('basicAuthMiddleware');
```

## Route Groups

Route groups allow you to organize related routes and share common attributes. Groups can be nested, and nested groups inherit attributes from their parent, much like variable scopes.

To group routes under a common prefix, use the `addGroup()` method. All routes defined within the group will share the specified prefix and any attributes you assign:

```php
$router->addGroup('/admin', function ($router) {
    // This middleware only affect routes within this group and any nested groups
    $router->addMiddleware([AdminMiddleware::class, 'handle']);
    // It may be ugly, but by passing an empty string it will only use the prefix (e.g /admin)
    $router->get('',           [AdminController::class, 'index']);
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->post('/settings', [AdminController::class, 'settings']);
});
```

You can also include parameters in group prefixes.

```php
$router->addGroup('/customers/{customerId}', function ($router) {
    // ...
});
```

You can also create a group without a prefix by providing an empty string. This is useful for sharing middlewares and parameter rules without affecting the route path:

```php
$router->addGroup('', function ($router) {
    // ...
});
```

## Named Routes

Named routes allow you to assign a unique name to a route, making it easier to generate URLs programmatically.

### Defining Named Routes

Specify the name by using either the third parameter (fourth if using `addRoute()`) or the named parameter `name`:

```php
$router->get('/user/{id}', 'handler', 'user.profile');
```

### Generating URLs

Use the `getUriFor()` method to generate a URL for a named route:

```php
$path = $router->getUriFor('user.profile', ['id' => 42]);
echo $path; // Outputs: /user/42
```

If you provide parameters that are not part of the route pattern, they will be appended as query parameters. For example:

```php
$path = $router->getUriFor('user.profile', ['id' => 42, 'extra' => 'value']);
echo $path; // Outputs: /user/42?extra=value
```


## Debugging

When developing your application, it's helpful to inspect the registered routes and their attributes to ensure everything is set up correctly.

### Listing All Routes

You can use the `getRoutes()` method to retrieve an array of all registered routes, including their HTTP methods, patterns, handlers, and any associated middleware or attributes:

```php
$routes = $router->getRoutes();
print_r($routes);
```

This will output a flat array of your routes.

### Dispatching Routes

To process incoming requests and execute the appropriate handlers, use the `dispatch()` method. This method matches the current request to a registered route and executes its handler. It takes the request method and request path as arguments:

```php
$router->dispatch($requestMethod, $requestPath);
```

If you do not provide the arguments, the `dispatch()` method will use the `$_SERVER['REQUEST_METHOD']` variable and internal `getRelativeRequestPath()` method to determine the request method and path.

### Dispatch Return Codes

The `dispatch()` method returns specific status codes to indicate the result of the routing process. These codes can be used to implement custom logic or debugging based on the outcome of the dispatch.

#### Return Codes

- **`Route2::DISPATCH_FOUND` (0):** Indicates that a route was successfully matched and executed.
- **`Route2::DISPATCH_NOT_FOUND` (1):** Indicates that no route matched the request path.
- **`Route2::DISPATCH_NOT_ALLOWED` (2):** Indicates that a route matched the request path, but the HTTP method is not allowed.
- **`Route2::DISPATCH_BLOCKED` (3):** Indicates that middleware blocked the request before reaching the route handler.

This is useful for debugging or implementing custom logic based on route matching.

### Getting the Latest Dispatched Route

To retrieve information about the latest dispatched route, you can use the `getDispatchedRoute()` method. This method returns details about the route that was dispatched:

```php
$router->dispatch('GET', '/user/123');
$dispatchedRoute = $router->getDispatchedRoute();
print_r($dispatchedRoute);
```

Helpful for debugging or logging purposes, as it provides insight into the route that was executed for the current request.

## Clean URLs: Removing `index.php` from Your Links

Want to make your URLs cleaner and more user-friendly by removing the script name (like `index.php`)? Here's how you can achieve this with different web servers:

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

## Custom Error Pages

The router allows you to define custom handlers for 404 (Page Not Found) and 405 (Method Not Allowed) errors. These handlers can be customized to display user-friendly error pages or perform specific actions when these errors occur.

### Custom 404 Handler

The `notFoundHandler` is called when no route matches the requested path:

```php
$router = new Route2(
    notFoundHandler: function ($method, $path) {
        http_response_code(404);
        echo "Oops! The page at $path could not be found.";
    }
);
```

### Custom 405 Handler

The `methodNotAllowedHandler` is called when a route matches the path but the HTTP method is not allowed:

```php
$router = new Route2(
    methodNotAllowedHandler: function ($method, $path, $allowedMethods) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowedMethods));
        echo "Sorry, the method $method is not allowed for $path. Allowed methods: " . implode(', ', $allowedMethods);
    }
);
```

## Performance

The router is rarely going to be the bottleneck of your application. Most likely you can ignore this section.

### How To Cache Routes

The router uses an efficient tree-based algorithm for resolving routes, however rebuilding this tree for each request is going to slow down performance. The solution? Cache the already existing route tree.

> **Note:**  
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized.

> **Note:**  
> When implementing route caching, care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically (for example, by writing to a temporary file and then renaming it) so that each request can always fully load a valid cache file without errors or partial data.

Here is a simple cache implementation:

```PHP
function build_routes($router) {
    $router->get('/', 'homepage_handler');
    $router->post('/submit-form', 'submit_form_handler');
    $router->get('/users/{id}', 'get_user_handler');
}

$router = new Wilaak\Http\Route2();

if (!file_exists('routecache.php')) {
    build_routes($router);
    file_put_contents('routecache.php', '<?php return ' . var_export($router->routes, true) . ';');
} else {
    $router->routes = require 'routecache.php';
}

$router->dispatch();
```

By storing your routes in a PHP file, you take advantage of PHP’s OPcache, which compiles and stores the route definitions in memory. Making startup times nearly instantaneous.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.
