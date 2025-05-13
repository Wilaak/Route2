<?php

namespace Wilaak\Http;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * A simple routing library for PHP web applications.
 * 
 * @author Wilaak
 * @license WTFPL-1.0
 */
class Route2
{
    /**
     * An array to store fallbacks.
     * 
     * The array is structured as follows:
     * - Each key is a prefix (e.g., "/api") that will be used to match the beginning of the request URI.
     * - Each value is a callback that will be executed when the fallback is triggered.
     * - The callable function should accept the request method and URI as parameters.
     */
    private static array $fallbacks = [];

    /**
     * Route tree structure.
     * 
     * The tree is structured as follows:
     * - Each key is a segment of the URI (e.g., (string) "/myroute").
     * - Each value is an array containing:
     *  - (int) "1337": An array of route identifiers.
     *  - Other segments as keys for further nesting.
     */
    private static array $routeTree = [];

    /**
     * Route creation context.
     * 
     * The context is used to store the current state of the route creation process.
     * - `$routeGroupPrefix`: The prefix to be applied to all routes in the group.
     * - `$middlewareStack`: An array of middleware functions to be executed before and after the controller.
     * - `$expressionStack`: An array of parameter expressions for validation.
     */
    private static string $routeGroupPrefix = '';
    private static array $middlewareStack = [];
    private static array $expressionStack = [];

    /**
     * Route attributes table.
     * 
     * The attributes table is structured as follows:
     * - Each key is a combination of the HTTP method and URI (e.g., "GET|POST /myroute").
     * - Each value is an associative array containing:
     *  - `controller`: The controller function to handle the route.
     *  - `middleware`: An array of middleware functions to execute.
     *  - `expression`: An array of parameter expressions for validation.
     */
    private static array $routeAttributes = [];

    /**
     * Route cache properties.
     */
    private static bool $cacheEnabled = false;
    private static bool $cacheGenerate = false;
    private static string $cacheFilepath;

    /**
     * Event hooks for the router lifecycle.
     *
     * The hooks are structured as follows:
     * - Each key is an event name (e.g., 'onDispatchStart', 'onRouteMatch').
     * - Each value is an array of callable functions to be executed for that event.
     */
    private static array $hooks = [];

    /**
     * Registers a callback for a specific event hook.
     *
     * @param string   $event    The name of the event (e.g., 'onDispatchStart', 'onRouteMatch').
     * @param callable $callback The callback function to register.
     *
     * @return void
     */
    public static function hook(string $event, callable $callback): void
    {
        self::$hooks[$event][] = $callback;
    }

    /**
     * Triggers all callbacks for a specific event.
     *
     * @param string $event The name of the event to trigger.
     * @param mixed  ...$args Arguments to pass to the callback functions.
     *
     * @return bool Returns true if the hook exists and was triggered, false otherwise.
     */
    private static function trigger(string $event, ...$args): bool
    {
        if (!isset(self::$hooks[$event])) {
            return false;
        }
        foreach (self::$hooks[$event] as $callback) {
            $callback(...$args);
        }
        return true;
    }

    /**
     * Sets a fallback function to be executed when no route matches.
     * 
     * @param string|null   $prefix   Optional prefix to be applied to the fallback route.
     * @param callable|null $callback The fallback function to execute.
     *                                It should accept the request method and URI as parameters.
     *                                Example: `Route2::fallback(function($method, $uri) { ... })`
     *
     * @return void
     */
    public static function fallback(?string $prefix = null, ?callable $callback = null): void
    {
        $prefix = $prefix ?? self::$routeGroupPrefix;
        $prefix = $prefix === '' ? '/' : $prefix;
        self::$fallbacks[$prefix] = $callback;
        // Sort the fallbacks by the length of the prefix in descending order.
        uksort(self::$fallbacks, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
    }

    /**
     * This method is called when a requested route is not found under `dispatch()`.
     * 
     * @param string $requestUri    The URI of the request.
     * 
     * @return callable|null Returns the fallback function if found, null otherwise.
     */
    private static function getFallback(string $requestUri): ?callable 
    {
        foreach (self::$fallbacks as $prefix => $callback) {
            if (str_starts_with($requestUri, $prefix)) {
                return $callback;
            }
        }
        return null;
    }

    /**
     * Loads and caches the route tree in a file for faster startup.
     * 
     * @param bool      $enabled  Whether to enable caching.
     * @param string    $filepath The path to the cache file.
     * @param int|false $expire   The cache expiration time in seconds. If false, the cache never expires.
     */
    public static function fromCache(bool $enabled = true, string $filepath = 'Route2.cache.php', int|false $expire = false): void
    {
        if ($enabled === false) {
            return;
        }
        if (self::$routeTree !== []) {
            throw new InvalidArgumentException(
                'Route caching cannot be enabled after routes have been defined.'
            );
        }
        self::$cacheFilepath = $filepath;

        if (file_exists($filepath) === false) {
            self::$cacheGenerate = true;
            return;
        }

        if ($expire === false || $expire === 0 || @filemtime($filepath) >= time() - $expire) {
            self::$routeTree = require $filepath;
            self::$cacheEnabled = true;
            return;
        }
        @unlink(self::$cacheFilepath);
        self::$cacheGenerate = true;
    }

    /**
     * Registers a new route in the routing tree.
     *
     * @param string              $methods     A pipe-separated list of HTTP methods (e.g., "GET|POST").
     * @param string              $uri         The URL pattern for the route. 
     *                                         Supports:
     *                                         - Required parameters `{param}`.
     *                                         - Optional parameters `{param?}`.
     *                                         - Wildcard parameters `{param*}` (matches everything after the parameter).
     * @param callable            $controller  The controller function to handle the route.
     * @param callable|array|null $before      Optional middleware to execute before the controller.
     * @param callable|array|null $after       Optional middleware to execute after the controller.
     * @param array               $expression  Optional parameter expressions:
     *                                         - An associative array where:
     *                                           - The key is the parameter name.
     *                                           - The value is either:
     *                                             - A regex string (e.g., `['id' => '[0-9]+']` to match numbers).
     *                                             - A function that validates the parameter 
     *                                               (e.g., `['id' => is_numeric(...)]`).
     *
     * @return void
     */
    public static function match(string $methods, string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        $methods = strtoupper(
            str_replace(' ', '', $methods)
        );

        $identifier = $methods . ' ' . self::$routeGroupPrefix . $uri;
        if (array_key_exists($identifier, self::$routeAttributes)) {
            throw new InvalidArgumentException("Found duplicate route entries for '{$identifier}'. Please check your routes.");
        }

        $middlewareStack = self::$middlewareStack;
        foreach (['before' => $before, 'after' => $after] as $key => $middleware) {
            if ($middleware !== null) {
                $middleware = is_array($middleware) ? $middleware : [$middleware];
                foreach ($middleware as $mw) {
                    if (!is_callable($mw)) {
                        throw new InvalidArgumentException('Each middleware must be a callable.');
                    }
                }
                $middlewareStack[$key] = array_merge(
                    $middlewareStack[$key] ?? [],
                    $middleware
                );
            }
        }

        foreach ($expression as $param => $regex) {
            if (!is_string($regex) && !is_callable($regex)) {
                throw new InvalidArgumentException("Expression for parameter '{$param}' must be a regex string or a callable function");
            }
        }

        self::$routeAttributes[$identifier] = [
            'controller' => $controller,
            'middleware' => $middlewareStack,
            'expression' => self::$expressionStack + $expression,
        ];

        if (self::$cacheEnabled) {
            return;
        }

        $segments = preg_replace('/\/\{(\w+)(\?|(\*))?\}(?=\/|$)/', '/*', $uri);
        $segments = preg_split('/(?=\/)/', self::$routeGroupPrefix . $segments, -1, PREG_SPLIT_NO_EMPTY);

        $currentNode = &self::$routeTree;
        foreach ($segments as $segment) {
            $currentNode[$segment] ??= [];
            $currentNode = &$currentNode[$segment];
        }

        $currentNode[1337][] = $identifier;
    }

    /** Shorthand for `Route2::match('GET', ...)` */
    public static function get(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('GET', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('POST', ...)` */
    public static function post(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('POST', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('PUT', ...)` */
    public static function put(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('PUT', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('DELETE', ...)` */
    public static function delete(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('DELETE', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('PATCH', ...)` */
    public static function patch(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('PATCH', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('OPTIONS', ...)` */
    public static function options(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('OPTIONS', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('GET|POST', ...)` */
    public static function form(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('GET|POST', $uri, $controller, $before, $after, $expression);
    }
    /** Shorthand for `Route2::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', ...)` */
    public static function any(string $uri, callable $controller, null|callable|array $before = null, null|callable|array $after = null, array $expression = []): void
    {
        self::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', $uri, $controller, $before, $after, $expression);
    }

    /**
     * Adds middleware to the "before" middleware stack.
     * Routes added after this method call will inherit the middleware.
     *
     * @param callable|array $middleware The middleware to add to the "before" stack.
     *                                   It can be a callable function/method or an array of callables.
     *
     * @return void
     */
    public static function before(callable|array $middleware): void
    {
        if (is_array($middleware)) {
            foreach ($middleware as $mw) {
                if (!is_callable($mw)) {
                    throw new InvalidArgumentException('Each middleware must be a callable.');
                }
                self::$middlewareStack['before'][] = $mw;
            }
        } else {
            self::$middlewareStack['before'][] = $middleware;
        }
    }

    /**
     * Adds middleware to the "after" middleware stack.
     * Routes added after this method call will inherit the middleware.
     * 
     * @param callable|array $middleware The middleware to add to the "after" stack.
     *                                   It can be a callable function/method or an array of callables.
     * 
     * @return void
     */
    public static function after(callable|array $middleware): void
    {
        if (is_array($middleware)) {
            foreach ($middleware as $mw) {
                if (!is_callable($mw)) {
                    throw new InvalidArgumentException('Each middleware must be a callable.');
                }
                self::$middlewareStack['after'][] = $mw;
            }
        } else {
            self::$middlewareStack['after'][] = $middleware;
        }
    }

    /**
     * Adds expressions to the expression stack.
     * Routes added after this method call will inherit the expression constraints.
     * 
     * @param array $expressions An associative array where:
     *                           - The key is the parameter name (e.g., 'id').
     *                           - The value is either:
     *                             - A regex string (e.g., `['id' => '[0-9]+']`) to match specific patterns.
     *                             - A callable function that validates the parameter
     *                               (e.g., `['id' => is_numeric(...)]`).
     *
     * @return void
     */
    public static function expression(array $expression): void
    {
        foreach ($expression as $param => $regex) {
            if (!is_string($regex) && !is_callable($regex)) {
                throw new InvalidArgumentException("Expression for parameter '{$param}' must be a regex string or a callable function");
            }
        }
        self::$expressionStack += $expression;
    }

    /**
     * Share attributes across multiple routes
     * Preserves and restores the previous state after the callback.
     * 
     * @param string|null   $prefix   Optional prefix to be applied to all routes in the group.
     * @param callable|null $callback A callback function that defines the routes within the group.
     * 
     * @return void
     */
    public static function group(?string $prefix = null, ?callable $callback = null): void
    {
        $previousPrefix          = self::$routeGroupPrefix;
        $previousMiddlewareStack = self::$middlewareStack;
        $previousExpressionStack = self::$expressionStack;

        self::$routeGroupPrefix .= $prefix ?? '';
        $callback();

        self::$routeGroupPrefix = $previousPrefix;
        self::$middlewareStack  = $previousMiddlewareStack;
        self::$expressionStack  = $previousExpressionStack;
    }

    /**
     * Gets the relative URI of the current HTTP request.
     * Example:
     * - `/index.php/myroute` → `/myroute`
     *
     * @return string Relative request path.
     */
    public static function getRelativeRequestUri(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'];

        $scriptName = $_SERVER['SCRIPT_NAME'];
        $scriptDir = dirname($scriptName) . '/';

        if (str_starts_with($requestUri, $scriptName)) {
            $requestUri = substr($requestUri, strlen($scriptName));
        }

        if ($requestUri === $scriptDir) {
            $requestUri = '/';
        }

        if ($requestUri === '') {
            $requestUri = '/';
        }

        return $requestUri;
    }

    /**
     * Matches a route in the tree structure based on the URI.
     *
     * @param string $requestUri The URI to match against the routes.
     *
     * @return array Returns the matched route details or an empty array if no match is found.
     */
    private static function matchRoute(string $requestUri): array
    {
        $segments = preg_split('/(?=\/)/', $requestUri, -1, PREG_SPLIT_NO_EMPTY);
        return self::matchRouteRecursive(self::$routeTree, $segments);
    }

    /**
     * Recursively matches a route in the tree structure.
     *
     * @param array  $tree     The current level of the route tree.
     * @param array  $segments The remaining URI segments to match.
     * @param bool   $wildcard Indicates if a wildcard match is active.
     *
     * @return array Returns the matched route details or an empty array if no match is found.
     */
    private static function matchRouteRecursive(array $tree, array $segments, bool $wildcard = false): array
    {
        if (!$segments) {
            return $tree[1337] ?? [];
        }

        $currentSegment = array_shift($segments);

        foreach ([$currentSegment, '/*'] as $key) {
            if (isset($tree[$key])) {
                $match = self::matchRouteRecursive($tree[$key], $segments, $key === '/*');
                if ($match) {
                    return $match;
                }
            }
        }

        return $wildcard ? ($tree[1337] ?? []) : [];
    }

    /**
     * Dispatches the request to the appropriate route.
     *
     * @param string|null $requestMethod The HTTP method of the request (e.g., GET, POST). If not provided, it will use the current request method.
     * @param string|null $requestUri    The URI of the request. If not provided, it will use the current relative request URI.
     *
     * @return bool Returns true if a route was matched and dispatched, false otherwise.
     */
    public static function dispatch(?string $requestMethod = null, ?string $requestUri = null): bool
    {
        $requestMethod = strtoupper(
            $requestMethod ?? $_SERVER['REQUEST_METHOD']
        );
        $requestUri = rawurldecode(
            strtok($requestUri ?? self::getRelativeRequestUri(), '?')
        );

        if (self::$cacheGenerate) {
            file_put_contents(
                self::$cacheFilepath,
                '<?php return ' . var_export(self::$routeTree, true) . ';'
            );
            self::$cacheGenerate = false;
        }

        $allowedMethods = [];
        $routes = self::matchRoute($requestUri);

        foreach ($routes as $route) {
            [$routeMethods, $routeUri] = explode(' ', $route, 2);

            $routePattern = preg_replace(
                ['/\{(\w+)\}/', '/\{(\w+)\?\}/', '/\{(\w+)\*\}/'],
                ['(?P<$1>[^/]+)', '(?P<$1>[^/]*)', '(?P<$1>.*)'],
                $routeUri
            );
            $routePattern = '#^' . $routePattern . '$#';
            if (!preg_match($routePattern, $requestUri, $matches)) {
                continue;
            }

            foreach ($matches as $key => $value) {
                if (is_string($key) && !empty($value)) {
                    $params[$key] = $value;
                }
            }

            foreach (self::$routeAttributes[$route]['expression'] as $param => $expression) {
                if (!isset($params[$param])) {
                    continue;
                }
                if (is_callable($expression)) {
                    $result = $expression($params[$param]);
                    if ($result === false) {
                        continue 2;
                    }
                    if ($result === true) {
                        continue;
                    }
                    $params[$param] = $result;
                }
                if (is_string($expression) && !preg_match('#^' . $expression . '$#', $params[$param])) {
                    continue 2;
                }
            }

            $routeMethods = explode('|', $routeMethods);
            array_push($allowedMethods, ...$routeMethods);
            if (!in_array($requestMethod, $routeMethods)) {
                continue;
            }

            if (!isset(self::$routeAttributes[$route])) {
                throw new OutOfBoundsException("Failed to fetch route attributes for '{$route}'. Maybe try rebuilding the route cache?");
            }

            self::trigger('routeFound', $requestMethod, $requestUri, $params ?? []);

            foreach (self::$routeAttributes[$route]['middleware']['before'] ?? [] as $middleware) {
                if (!self::trigger('invokeMiddleware', $middleware)) {
                    $middleware();
                }
            }
            if (!self::trigger('invokeController', self::$routeAttributes[$route]['controller'], $params ?? [])) {
                self::$routeAttributes[$route]['controller'](...$params ?? []);
            }
            foreach (self::$routeAttributes[$route]['middleware']['after'] ?? [] as $middleware) {
                if (!self::trigger('invokeMiddleware', $middleware)) {
                    $middleware();
                }
            }
            return true;
        }
        if (!empty($allowedMethods)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            if (!self::trigger('methodNotAllowed', $requestMethod, $requestUri, $allowedMethods)) {
                echo '405 Method Not Allowed';
            }
            return false;
        }

        http_response_code(404);
        $fallback = self::getFallback($requestUri);
        $fallback = $fallback ?? function() {
            echo '404 Not Found';
        };
        if (!self::trigger('routeNotFound', $requestMethod, $requestUri, $fallback)) {
            $fallback($requestMethod, $requestUri);
        }
        return false;
    }
}
