<?php

namespace Wilaak\Http;

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
     */
    static array $routeTree = [];

    /**
     * Current state of the route creation process.
     */
    static array $routeCreationContext = [
        'prefix' => '',
        'before' => [],
        'after' => [],
        'expression' => [],
    ];

    /**
     * Adds a route to the route tree.
     */
    static function match(string $methods, string $uri, string|array $handler): void
    {
        $routeUri     = self::$routeCreationContext['prefix'] . $uri;
        $routeMethods = explode('|', strtoupper($methods));

        $segments = preg_replace('/\/\{(\w+)(\?|(\*))?\}(?=\/|$)/', '/*', $uri);
        $segments = preg_split('/(?=\/)/', self::$routeCreationContext['prefix'] . $segments, -1, PREG_SPLIT_NO_EMPTY);

        $routePattern = preg_replace(
            ['/\{(\w+)\}/', '/\{(\w+)\?\}/', '/\{(\w+)\*\}/'],
            ['(?P<$1>[^/]+)', '(?P<$1>[^/]*)', '(?P<$1>.*)'],
            $routeUri
        );
        $routePattern = '#^' . $routePattern . '$#';

        $currentNode = &self::$routeTree;
        foreach ($segments as $segment) {
            $currentNode[$segment] ??= [];
            $currentNode = &$currentNode[$segment];
        }

        $currentNode[1337][] = [
            'pattern'    => $routePattern,
            'methods'    => $routeMethods,
            'handler'    => $handler,
            'before'     => self::$routeCreationContext['before'],
            'after'      => self::$routeCreationContext['after'],
            'expression' => self::$routeCreationContext['expression'],
        ];
    }

    /** Shorthand for `Route2::match('GET', ...)` */
    public static function get(string $uri, string|array $handler): void
    {
        self::match('GET', $uri, $handler);
    }
    /** Shorthand for `Route2::match('POST', ...)` */
    public static function post(string $uri, string|array $handler): void
    {
        self::match('POST', $uri, $handler);
    }
    /** Shorthand for `Route2::match('PUT', ...)` */
    public static function put(string $uri, string|array $handler): void
    {
        self::match('PUT', $uri, $handler);
    }
    /** Shorthand for `Route2::match('DELETE', ...)` */
    public static function delete(string $uri, string|array $handler): void
    {
        self::match('DELETE', $uri, $handler);
    }
    /** Shorthand for `Route2::match('PATCH', ...)` */
    public static function patch(string $uri, string|array $handler): void
    {
        self::match('PATCH', $uri, $handler);
    }
    /** Shorthand for `Route2::match('OPTIONS', ...)` */
    public static function options(string $uri, string|array $handler): void
    {
        self::match('OPTIONS', $uri, $handler);
    }
    /** Shorthand for `Route2::match('GET|POST', ...)` */
    public static function form(string $uri, string|array $handler): void
    {
        self::match('GET|POST', $uri, $handler);
    }
    /** Shorthand for `Route2::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', ...)` */
    public static function any(string $uri, string|array $handler): void
    {
        self::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', $uri, $handler);
    }

    /**
     * Adds middlewares to the route creation context.
     */
    static function before(array $middlewares): void
    {
        foreach ($middlewares as $middleware) {
            self::$routeCreationContext['before'][] = $middleware;
        }
    }

    /**
     * Adds middlewares to the route creation context.
     */
    static function after(array $middlewares): void
    {
        foreach ($middlewares as $middleware) {
            self::$routeCreationContext['after'][] = $middlewares;
        }
    }

    /**
     * Adds expressions to the route creation context.
     */
    static function expression(array $expression): void
    {
        self::$routeCreationContext['expression'] = array_merge(
            self::$routeCreationContext['expression'],
            $expression
        );
    }

    /**
     * Share attributes across multiple routes. Preserves and restores the previous state after the callback.
     */
    static function group(?string $prefix = null, ?callable $callback = null): void
    {
        $previousContext = self::$routeCreationContext;
        if ($prefix !== null) {
            self::$routeCreationContext['prefix'] .= $prefix;
        }
        $callback();
        self::$routeCreationContext = $previousContext;
    }

    /**
     * Returns the relative request URI.
     */
    static function getRelativeRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'];
        $script = $_SERVER['SCRIPT_NAME'];
        $dir = dirname($script) . '/';

        if (str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        }
        if ($uri === $dir || $uri === '') {
            return '/';
        }
        return $uri;
    }

    /**
     * Matches a route in the tree structure based on the URI.
     */
    private static function matchRoute(string $requestUri): array
    {
        $segments = preg_split('/(?=\/)/', $requestUri, -1, PREG_SPLIT_NO_EMPTY);
        return self::matchRouteRecursive(self::$routeTree, $segments);
    }

    /**
     * Recursively matches a route in the tree structure.
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
     * Dispatch the request to the appropriate route.
     */
    static function dispatch(?string $requestMethod = null, ?string $requestUri = null): bool
    {
        $requestMethod = strtoupper(
            $requestMethod ?? $_SERVER['REQUEST_METHOD']
        );
        $requestUri = rawurldecode(
            strtok($requestUri ?? self::getRelativeRequestUri(), '?')
        );

        $allowedMethods = [];
        $routes = self::matchRoute($requestUri);
        foreach ($routes as $route) {
            $matches = [];
            if (!preg_match($route['pattern'], $requestUri, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key) && !empty($value)) {
                    $params[$key] = $value;
                }
            }

            foreach ($route['expression'] as $param => $expression) {
                if (!isset($params[$param])) {
                    continue;
                }
                if (str_starts_with($expression, '#^') && str_ends_with($expression, '$#')) {
                    if (!preg_match($expression, $params[$param])) {
                        continue 2;
                    }
                    continue;
                }

                $result = $expression($params[$param]);
                if ($result === false) {
                    continue 2;
                }
                if ($result === true) {
                    continue;
                }
                $params[$param] = $result;
            }

            array_push($allowedMethods, ...$route['methods']);
            if (!in_array($requestMethod, $route['methods'])) {
                continue;
            }

            foreach ($route['before'] as $middleware) {
                $result = is_array($middleware)
                    ? (new $middleware[0]())->{$middleware[1]}()
                    : $middleware();
                if ($result === false) {
                    return false;
                }
            }

            if (is_array($route['handler'])) {
                (new $route['handler'][0]())->{$route['handler'][1]}(...$params);
            } else {
                $route['handler'](...$params);
            }

            foreach ($route['after'] as $middleware) {
                $result = is_array($middleware)
                    ? (new $middleware[0]())->{$middleware[1]}()
                    : $middleware();
                if ($result === false) {
                    return false;
                }
            }

            return true;
        }
        if (!empty($allowedMethods)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            echo '405 Method Not Allowed';
            return false;
        }
        http_response_code(404);
        echo '404 Not Found';
        return false;
    }
}
