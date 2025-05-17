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
    const ROUTE_NODE = '_route';
    const PARAM_NODE = '_param';
    
    static array $routeTree = [];

    static array $buildContext = [
        'prefix'      => '',
        'before'      => [],
        'after'       => [],
        'expressions' => [],
    ];

    static function match(string $methods, string $uri, string|array $handler): void
    {
        $methods  = explode('|', strtoupper($methods));
        $segments = preg_split('/(?=\/)/', self::$buildContext['prefix'] . $uri, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($segments as $key => $segment) {
            if (!str_starts_with($segment, '/{'))
                continue;
            $segments[$key] = self::PARAM_NODE;
        }
        $currentNode = &self::$routeTree;
        foreach ($segments as $segment) {
            $currentNode[$segment] ??= [];
            $currentNode = &$currentNode[$segment];
        }

        $pattern  = preg_replace(
            ['/\{(\w+)\}/', '/\{(\w+)\?\}/', '/\{(\w+)\*\}/'],
            ['(?P<$1>[^/]+)', '(?P<$1>[^/]*)', '(?P<$1>.*)'],
            self::$buildContext['prefix'] . $uri
        );
        $pattern = '#^' . $pattern . '$#';

        $currentNode[self::ROUTE_NODE][] = [
            'pattern'     => $pattern,
            'methods'     => $methods,
            'handler'     => $handler,
            'before'      => self::$buildContext['before'],
            'after'       => self::$buildContext['after'],
            'expressions' => self::$buildContext['expressions'],
        ];
    }

    static function get($uri, $handler): void { self::match('GET', $uri, $handler); }
    static function post($uri, $handler): void { self::match('POST', $uri, $handler); }
    static function put($uri, $handler): void { self::match('PUT', $uri, $handler); }
    static function delete($uri, $handler): void { self::match('DELETE', $uri, $handler); }
    static function patch($uri, $handler): void { self::match('PATCH', $uri, $handler); }
    static function options($uri, $handler): void { self::match('OPTIONS', $uri, $handler); }
    static function form($uri, $handler): void { self::match('GET|POST', $uri, $handler); }
    static function any($uri, $handler): void { self::match('GET|POST|PUT|DELETE|PATCH|OPTIONS', $uri, $handler); }

    static function before(array $middlewares): void
    {
        self::$buildContext['before'] = array_merge(self::$buildContext['before'], $middlewares);
    }

    static function after(array $middlewares): void
    {
        self::$buildContext['after'] = array_merge(self::$buildContext['after'], $middlewares);
    }

    static function expression(array $expression): void
    {
        self::$buildContext['expressions'] += $expression;
    }

    static function group(?string $prefix = null, ?callable $callback = null): void
    {
        $previousContext = self::$buildContext;
        if ($prefix !== null) {
            self::$buildContext['prefix'] .= $prefix;
        }
        $callback();
        self::$buildContext = $previousContext;
    }

    static function getRelativeRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'];
        $script = $_SERVER['SCRIPT_NAME'];

        if (str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        }
        return $uri === '/' || $uri === '' ? '/' : $uri;
    }

    static function findMatchingRoutes(string $requestUri): array
    {
        $segments = preg_split('/(?=\/)/', $requestUri, -1, PREG_SPLIT_NO_EMPTY);
        $tree = self::$routeTree;
        foreach ($segments as $segment) {
            if (isset($tree[$segment])) {
                $tree = $tree[$segment];
            } elseif (isset($tree[self::PARAM_NODE])) {
                $tree = $tree[self::PARAM_NODE];
            }
        }
        return $tree[self::ROUTE_NODE] ?? [];
    }

    static function dispatch(?string $requestMethod = null, ?string $requestUri = null): bool
    {
        $requestMethod  = strtoupper($requestMethod ?? $_SERVER['REQUEST_METHOD']);
        $requestUri     = rawurldecode(strtok($requestUri ?? self::getRelativeRequestUri(), '?'));
        $allowedMethods = [];

        foreach (self::findMatchingRoutes($requestUri) as $route) {
            $matches = [];
            if (!preg_match($route['pattern'], $requestUri, $matches)) {
                continue;
            }

            $parameters = [];
            foreach ($matches as $key => $value) {
                if (is_string($key) && !empty($value)) {
                    $parameters[$key] = $value;
                }
            }

            foreach ($route['expressions'] as $parameterName => $expression) {
                if (!isset($parameters[$parameterName])) {
                    continue;
                }
                if (str_starts_with($expression, '#^') && str_ends_with($expression, '$#')) {
                    if (!preg_match($expression, $parameters[$parameterName])) {
                        continue 2;
                    }
                    continue;
                }
                $result = (self::getHandler($expression, $parameters[$parameterName]))();
                if ($result === false) {
                    continue 2;
                }
                if ($result === true) {
                    continue;
                }
                $parameters[$parameterName] = $result;
            }

            array_push($allowedMethods, ...$route['methods']);
            if (!in_array($requestMethod, $route['methods'])) {
                continue;
            }

            foreach ($route['before'] as $middleware) {
                (self::getHandler($middleware))();
            }

            (self::getHandler($route['handler'], $parameters))();

            foreach ($route['after'] as $middleware) {
                (self::getHandler($middleware))();
            }
            return true;
        }
        if ($allowedMethods !== []) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            echo '405 Method Not Allowed';
        } else {
            http_response_code(404);
            echo '404 Not Found';
        }
        return false;
    }

    static function getHandler(string|array $handler, array $parameters = []): callable
    {
        if (is_array($handler)) {
            $instance = new $handler[0]();
            return fn() => $instance->{$handler[1]}();
        } else {
            return fn() => $handler(...$parameters);
        }
    }
}
