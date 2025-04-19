<?php

namespace Wilaak\Http;

use InvalidArgumentException;

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
     * - Each node represents a segment of the URI.
     * - Each segment can have child segments.
     * - The `(int) 1337` key contains an array of child nodes.
     * - Each child node contains:
     *  - `methods`: An array of HTTP methods (e.g., GET, POST).
     *  - `uri`: The URI pattern for the route.
     *  - `controller`: The controller function to handle the route.
     *  - `middleware`: An array of middleware functions to execute
     */
    private static array $routeTree = [];

    /**
     * Route addition context.
     */
    private static string $routeGroupPrefix = '';
    private static array $middlewareStack = [];
    private static array $expressionStack = [];

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
        $methods = explode('|', strtoupper($methods));

        $middlewareStack = self::$middlewareStack;
        if ($middleware) {
            $middlewareStack['before'][] = $middleware;
        }

        $segments = preg_replace('/\{(\w+)(\?|(\*))?\}/', '*', $uri);
        $segments = preg_split('/(?=\/)/', self::$routeGroupPrefix . $segments, -1, PREG_SPLIT_NO_EMPTY);

        $currentNode = &self::$routeTree;
        foreach ($segments as $segment) {
            $currentNode[$segment] ??= [];
            $currentNode = &$currentNode[$segment];
        }

        $currentNode[1337][] = [
            'uri'        => self::$routeGroupPrefix . $uri,
            'methods'    => $methods,
            'controller' => $controller,
            'middleware' => $middlewareStack,
            'expression' => self::$expressionStack + $expression,
        ];
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
     * @param string|null $method The HTTP method to match against the routes. If null, uses the current request method.
     * @param string|null $uri    The URI to match against the routes. If null, uses the current relative request URI.
     *
     * @return bool True if a route was matched and dispatched, false otherwise.
     */
    public static function dispatch(?string $method = null, ?string $uri = null): bool
    {
        $method = strtoupper($method ?? $_SERVER['REQUEST_METHOD']);
        $uri = rawurldecode(strtok($uri ?? self::getRelativeRequestUri(), '?'));

        $routes = self::matchRoute($uri);

        foreach ($routes as $route) {
            if (!in_array($method, $route['methods'])) {
                continue;
            }

            $pattern = preg_replace(
                ['/\{(\w+)\}/', '/\{(\w+)\?\}/', '/\{(\w+)\*\}/'],
                ['(?P<$1>[^/]+)', '(?P<$1>[^/]*)', '(?P<$1>.*)'],
                $route['uri']
            );
            $pattern = '#^' . $pattern . '$#';

            if (!preg_match($pattern, $uri, $matches)) {
                continue;
            }

            foreach ($matches as $key => $value) {
                if (is_string($key) && !empty($value)) {
                    $params[$key] = $value;
                }
            }

            foreach ($route['expression'] as $param => $expression) {
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

            foreach ($route['middleware']['before'] ?? [] as $middleware) {
                $middleware();
            }

            $route['controller'](...$params ?? []);

            foreach ($route['middleware']['after'] ?? [] as $middleware) {
                $middleware();
            }

            return true;
        }

        return false;
    }
}
