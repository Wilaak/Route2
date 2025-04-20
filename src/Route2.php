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
     * Route tree structure.
     * 
     * The tree is structured as follows:
     * - Each key is a segment of the URI (e.g., (string) "/myroute").
     * - Each value is an array containing:
     *  - (int) "1337": An array of route identifiers.
     *  - Other segments as keys for further nesting.
     */
    static array $routeTree = [];

    /**
     * Route creation context.
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
    static array $routeAttributes = [];

    /**
     * Route cache properties.
     */
    static bool $cacheEnabled = false;
    static bool $cacheGenerate = false;
    static string $cacheFilepath;

    /**
     * Loads and stores the route tree in a file for faster startup time.
     *
     * @param bool      $enabled  Whether to enable caching.
     * @param int|false $expire   The cache expiration time in seconds. If false, the cache never expires.
     * @param string    $filepath The path to the cache file.
     *
     * @throws InvalidArgumentException If route caching is enabled after routes have been defined.
     */
    public static function fromCache(bool $enabled = true, int|false $expire = false, string $filepath = 'Route2.cache.php'): void
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
     * Adds a new route to the routing tree.
     *
     * @param string        $methods     A pipe-separated list of HTTP methods (e.g., "GET|POST").
     * @param string        $uri         The URL pattern for the route. 
     *                                   Supports:
     *                                   - Required parameters `{param}`.
     *                                   - Optional parameters `{param?}`.
     *                                   - Wildcard parameters `{param*}` (matches everything after the parameter).
     * @param callable      $controller  The controller function to handle the route.
     * @param callable|null $middleware  Optional middleware to execute before the controller.
     * @param array         $expression  Optional parameter expressions:
     *                                   - An associative array where:
     *                                     - The key is the parameter name.
     *                                     - The value is either:
     *                                       - A regex string (e.g., `['id' => '[0-9]+']` to match numbers).
     *                                       - A function that validates the parameter 
     *                                         (e.g., `['id' => is_numeric(...)]`).
     *
     * @return void
     */
    public static function match(string $methods, string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        $methods = strtoupper(
            str_replace(' ', '', $methods)
        );

        $identifier = $methods . ' ' . self::$routeGroupPrefix . $uri;
        if (array_key_exists($identifier, self::$routeAttributes)) {
            throw new InvalidArgumentException("Found duplicate route entries for '{$identifier}'. Please check your routes.");
        }

        $middlewareStack = self::$middlewareStack;
        if ($middleware !== null) {
            $middlewareStack['before'][] = $middleware;
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

    /**
     * Shorthand for `Route2::match('GET', ...)`
     */
    public static function get(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('GET', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('POST', ...)`
     */
    public static function post(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('POST', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('PUT', ...)`
     */
    public static function put(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('PUT', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('DELETE', ...)`
     */
    public static function delete(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('DELETE', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('PATCH', ...)`
     */
    public static function patch(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('PATCH', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('OPTIONS', ...)`
     */
    public static function options(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('OPTIONS', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('GET|POST', ...)`
     */
    public static function form(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('GET|POST', $uri, $controller, $middleware, $expression);
    }

    /**
     * Shorthand for `Route2::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', ...)`
     */
    public static function any(string $uri, callable $controller, ?callable $middleware = null, array $expression = []): void
    {
        self::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', $uri, $controller, $middleware, $expression);
    }

    /**
     * Adds a middleware to the "before" middleware stack.
     *
     * Routes added after this method call will inherit the middleware.
     *
     * @param callable $middleware The middleware to add to the "before" stack.
     *                             It should be a callable function or method.
     *
     * @return void
     */
    public static function before(callable $middleware): void
    {
        self::$middlewareStack['before'][] = $middleware;
    }

    /**
     * Adds a middleware to the "after" middleware stack.
     * 
     * Routes added after this method call will inherit the middleware.
     * 
     * @param callable $middleware The middleware to add to the "after" stack.
     *                             It should be a callable function or method.
     * 
     * @return void
     */
    public static function after(callable $middleware): void
    {
        self::$middlewareStack['after'][] = $middleware;
    }

    /**
     * Adds expressions to the expression stack.
     *
     * Routes added after this method call will inherit the expression constraints.
     * 
     * @param array $expressions An associative array where:
     *                           - The key is the parameter name (e.g., 'id').
     *                           - The value is either:
     *                             - A regex string (e.g., `['id' => '[0-9]+']`) to match specific patterns.
     *                             - A callable function that validates the parameter
     *                               (e.g., `['id' => is_numeric(...)]`).
     *
     * @throws InvalidArgumentException If an expression is neither a string nor a callable.
     * @return void
     */
    public static function expression(array $expression): void
    {
        foreach ($expression as $param => $regex) {
            if (!is_string($regex) && !is_callable($regex)) {
                throw new InvalidArgumentException("Expression for parameter '{$param}' must be a regex string or a callable function");
            }
        }
        self::$expressionStack[] = $expression;
    }

    /**
     * Share attributes across multiple routes
     * 
     * Preserves and restores the previous state after the callback.
     * 
     * @param string|null   $prefix   Optional prefix to be applied to all routes in the group.
     * @param callable|null $callback A callback function that defines the routes within the group. Required.
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
     *
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
     * @return bool True if a route was matched and dispatched, false otherwise.
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
        }

        $routes = self::matchRoute($requestUri);

        foreach ($routes as $route) {

            [$routeMethods, $routeUri] = explode(' ', $route, 2);

            if (!in_array($requestMethod, explode('|', $routeMethods))) {
                continue;
            }

            $routePattern = preg_replace(
                ['/\{(\w+)\}/', '/\{(\w+)\?\}/', '/\{(\w+)\*\}/'],
                ['(?P<$1>[^/]+)', '(?P<$1>[^/]*)', '(?P<$1>.*)'],
                $routeUri
            );
            $routePattern = '#^' . $routePattern . '$#';
            if (!preg_match($routePattern, $requestUri, $matches)) {
                continue;
            }

            if (!isset(self::$routeAttributes[$route])) {
                throw new OutOfBoundsException("Failed to fetch route attributes for '{$route}'. Maybe try rebuilding the route cache?");
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
                if (is_callable($expression) && !$expression($params[$param])) {
                    continue 2;
                }
                if (is_string($expression) && !preg_match('#^' . $expression . '$#', $params[$param])) {
                    continue 2;
                }
                if (!is_string($expression) && !is_callable($expression)) {
                    throw new InvalidArgumentException(
                        "Expression for parameter '{$param}' must be a regex string or callable function"
                    );
                }
            }
            foreach (self::$routeAttributes[$route]['middleware']['before'] ?? [] as $middleware) {
                $middleware();
            }
            self::$routeAttributes[$route]['controller'](...$params ?? []);
            foreach (self::$routeAttributes[$route]['middleware']['after'] ?? [] as $middleware) {
                $middleware();
            }
            return true;
        }
        return false;
    }
}
