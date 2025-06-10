# Route2 - Autowiring PHP request router

### Overview

- **Autowiring:**  
    Integrate with any PSR-11 compatible container for autowiring of handler dependencies.

- **Route Parameters:**  
    Support for dynamic segments like required (`/user/{id}`), optional (`/user/{id?}`), and wildcards (`/files/{path*}`).

- **Parameter Validation:**  
    Validate or transform parameters using regex or custom logic.

- **Middleware Support:**  
    Execute logic before route handlers (e.g., authentication, logging).

- **Route Groups:**  
    Group routes under a common prefix and share attributes across multiple routes.

- **Named routes:**  
    Assign names to routes and generate URLs from route names and parameters.

- **Method Not Allowed:**  
    Returns a compliant "405 Method Not Allowed" response, as specified by the HTTP standard.

- **High Performance:**  
    Efficient tree-based route resolution with easy to implement route caching for near instantaneous startup.

## Table of contents

- [Install](#install)
- [What is a Handler?](#what-is-a-handler)
- [Autowiring](#autowiring)
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
- [Performance](#performance)
    - [How To Cache Routes](#how-to-cache-routes)
- [Debugging](#debugging)
    - [Listing All Routes](#listing-all-routes)
    - [Accessing Routes](#accessing-routes)
    - [Dispatching Routes](#dispatching-routes)
    - [Dispatch Return Codes](#dispatch-return-codes)
    - [Finding Matching Routes](#finding-matching-routes)
    - [Getting the Latest Matched Route](#getting-the-latest-matched-route)
- [Clean URLs: Removing `index.php` from Your Links](#clean-urls-removing-indexphp-from-your-links)
    - [FrankenPHP](#frankenphp)
    - [NGINX](#nginx)
    - [Apache](#apache)
- [Custom Error Pages](#custom-error-pages)
    - [Defining Custom Handlers](#defining-custom-handlers)
- [License](#license)

## Install

Install with composer:

    composer require wilaak/route2

Or simply include it in your project:

```PHP
require '/path/to/Route2.php'
```

Requires PHP 8.3 or newer

## What is a Handler?

A handler is simply a reference to the function or method that will be executed.

You can define handlers in a variety of ways:

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

- **Anonymous Function (Closure)**  
    > **⚠️ Important:**  
    > Using anonymous functions is **not going to work** if you want to cache the routes, as closures cannot be easily serialized.

    You can use an anonymous function directly as a handler:
    ```php
    function () {
        echo 'Hello!';
    }
    ```
## Autowiring

By supplying a PSR-11 compatible dependency injection container, the router can automatically resolve and inject type-hinted dependencies into route handlers.

```PHP
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

$router->get('/', 'homepage_handler');

function homepage_handler(DatabaseService $db) {
    echo "Index page with database connection!";
}
```

In the example above, we are using [PicoDI](https://github.com/Wilaak/PicoDI), a stupidly simple container.

Internally, the router leverages PHP's Reflection API to analyze and resolve dependencies dynamically.

Not familiar with dependency injection containers? Read [Understanding Dependency Injection](https://php-di.org/doc/understanding-di.html) to learn more. It's a great tool for centralizing the configuration of dependencies and making your code more loosely coupled.

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
function handler($post, $comment) {
    echo 'Showing post:' . $post . ' and comment: ' . $comment;
}

$router->get('/posts/{post}/comments/{comment}', 'handler');
```

### Optional Parameters

Specify a route parameter that may not always be present in the URI. You may do so by placing a `?` mark after the parameter name.

>**Note:** Must be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($name = 'John') {
    echo $name;
}

$router->get('/user/{name?}', 'handler');
```

### Wildcard Parameters

Capture the whole segment including slashes by placing a `*` after the parameter name.

>**Note:** Must be the last segment. Make sure to give the route's corresponding variable a default value:

```php
function handler($any = 'Empty') {
    echo $any;
}

$router->get('/somewhere/{any*}', 'handler');
```

### Parameter Rules

You can enforce specific formats for your route parameters by using the `addParameterRules()` method. This method accepts an associative array where the keys represent parameter names, and the values can either be a regex pattern or a [handler](#what-is-a-handler).

- **Regex**: Ensures the parameter matches the specified pattern. If not, the route will be skipped.
- **Handler**: The [handler](#what-is-a-handler) receives the parameter value as its argument. It should return `true` to allow the parameter, `false` to skip the route, or any other value to assign it directly to the parameter.

Routes registered after this method will inherit the rules.

```php
$router->addParameterRules([
    // By specifying #^ ... $# you are telling the expression to use regex
    'id' => '#^[0-9]+$#',
    // A handler to verify that the value of id is numeric
    'id' => 'is_numeric',
    // A handler to transform the parameter value
    'name' => 'strtoupper'
]);
```

## Middleware

A middleware is a [handler](#what-is-a-handler) that executes before route handlers. You can imagine them as a series of layers your request has to pass through before reaching your route handler. For example, you might use middleware to check if a user is authenticated, log request details, or modify the response before sending it to the client.

> **Note:** If a middleware handler returns `false` any further processing will be halted.

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

$router->addMiddleware('basicAuthMiddleware');
```

## Route Groups

Route groups make it easy to organize related routes and share common attributes. Groups can be nested, and nested groups inherit attributes from their parent, much like variable scopes.

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

You can also create a group without a prefix by providing an empty string. This is useful for sharing route attributes without affecting the route path:

```php
$router->addGroup('', function ($router) {
    // ...
});
```

## Named Routes

Named routes allow you to assign a unique name to a route, making it easier to generate URLs programmatically. This is particularly useful for creating links or redirects without hardcoding the route paths.

### Defining Named Routes

You can assign a name to a route by specifying it in the `name` attribute when registering the route:

```php
$router->get('/user/{id}', 'handler', 'user.profile');
```

### Generating URLs

Named routes simplify URL generation by allowing you to dynamically create paths based on route names and parameters. Use the `getUriFor()` method to generate a URL for a named route:

```php
$path = $router->getUriFor('user.profile', ['id' => 42]);
echo $path; // Outputs: /user/42
```

> **Note:**  
> The `getUriFor` method generates only the path portion of the URL. If you provide additional parameters that are not part of the route definition, they will be appended as query parameters. For example:

```php
$path = $router->getUriFor('user.profile', ['id' => 42, 'extra' => 'value']);
echo $path; // Outputs: /user/42?extra=value
```

## Performance

### How To Cache Routes

The router uses an efficient tree-based algorithm for resolving routes, however rebuilding this tree for each request is going to slow down performance. The solution? Cache the already existing route tree.

> **⚠️ Warning:**  
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

Storing your routes in a PHP file allows PHP’s OPcache to efficiently compile and cache the route definitions in memory. This dramatically reduces startup time, enabling your application to boot almost instantly.

## Debugging

When developing your application, it's helpful to inspect the registered routes and their attributes to ensure everything is set up correctly.

### Listing All Routes

You can use the `getRoutes()` method to retrieve an array of all registered routes, including their HTTP methods, patterns, handlers, and any associated middleware or attributes:

```php
$routes = $router->getRoutes();
print_r($routes);
```

This will output a structured array of your routes, which is useful for debugging route registration issues or verifying middleware assignments.

### Accessing Routes

Once you have registered your routes and dispatched them, you can access them in two ways:

1. **Rewriting the URL**  
    If your server is configured to hide the script name (e.g., `index.php`), you can simply use the rewritten URL to access your routes. For example:
    ```
    https://example.com/your/path
    ```

2. **Appending the Path to the PHP File**  
    If the script name is not hidden, you can append the route path directly to the PHP file name. For example:
    ```
    https://example.com/index.php/your/path
    ```

### Dispatching Routes

To process incoming requests and execute the appropriate route handler, use the `dispatch()` method. This method matches the current request to a registered route and executes its handler. It takes the request method and request path as arguments:

```php
$router->dispatch($requestMethod, $requestPath);
```

If you do not provide the arguments, the `dispatch()` method will automatically use the `$_SERVER['REQUEST_METHOD']` variable and internal `getRelativeRequestPath()` method to determine the request method and path.

### Dispatch Return Codes

The `dispatch()` method returns specific status codes to indicate the result of the routing process. These codes can be used to implement custom logic or debugging based on the outcome of the dispatch.

#### Return Codes

- **`Route2::DISPATCH_OK` (0):** Indicates that a route was successfully matched and executed.
- **`Route2::DISPATCH_NOT_FOUND` (1):** Indicates that no route matched the request path.
- **`Route2::DISPATCH_METHOD_NOT_ALLOWED` (2):** Indicates that a route matched the request path, but the HTTP method is not allowed.
- **`Route2::DISPATCH_MIDDLEWARE_BLOCKED` (3):** Indicates that middleware blocked the request before reaching the route handler.

These constants are defined in the router class and can be used for debugging or handling specific scenarios during the dispatch process.

### Finding Matching Routes

If you need to manually inspect or retrieve routes that match a specific request, you can use the `findMatchingRoutes()` method. This method returns an array of routes that match the given request:

```php
$matchingRoutes = $router->findMatchingRoutes($request);
print_r($matchingRoutes);
```

This is useful for debugging or implementing custom logic based on route matching.

### Getting the Latest Matched Route

To retrieve information about the latest matched route, you can use the `getMatchedRoute()` method. This method returns details about the route that was most recently matched during the dispatch process:

```php
$router->dispatch($requestMethod, $requestPath);
$matchedRoute = $router->getMatchedRoute();
print_r($matchedRoute);
```

This is particularly helpful for debugging or logging purposes, as it provides insight into the route that was executed for the current request.

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

### Defining Custom Handlers

You can provide custom closures for the `notFoundHandler` and `methodNotAllowedHandler` when initializing the router:

```php
$router = new Route2(
    notFoundHandler: function ($method, $path) {
        http_response_code(404);
        echo "Oops! The page at $path could not be found.";
    },
    methodNotAllowedHandler: function ($method, $path, $allowedMethods) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowedMethods));
        echo "Sorry, the method $method is not allowed for $path. Allowed methods: " . implode(', ', $allowedMethods);
    }
);
```

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.
