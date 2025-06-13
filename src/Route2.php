<?php

namespace Wilaak\Http;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

use Psr\Container\ContainerInterface;

class Route2
{
    private array $context = [
        'prefix'      => '',
        'middleware'  => [],
        'rules'       => [],
    ];

    private int $routeOrder = 0;

    public array $routes = [
        'names' => [],
        'tree'  => [],
    ];

    public const DISPATCH_FOUND       = 0;
    public const DISPATCH_NOT_FOUND   = 1;
    public const DISPATCH_NOT_ALLOWED = 2;
    public const DISPATCH_BLOCKED     = 3;

    private const NODE_PARAMETER = 69;
    private const NODE_WILDCARD  = 420;
    private const NODE_ROUTES    = 1337;

    private ?array $lastDispatchedRoute = null;

    private Closure $notFoundHandler;
    private Closure $methodNotAllowedHandler;

    public function __construct(
        ?Closure $notFoundHandler = null,
        ?Closure $methodNotAllowedHandler = null,
        private ?object $container = null
    ) {
        $this->notFoundHandler = $notFoundHandler ?? function ($method, $path) {
            http_response_code(404);
            echo "404 Not found";
        };
        $this->methodNotAllowedHandler = $methodNotAllowedHandler ?? function ($method, $path, $allowedMethods) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            echo "405 Method not allowed";
        };
    }

    public function addRoute(array $methods, string $pattern, mixed $handler, ?string $name = null): void
    {
        $fullPattern = $this->context['prefix'] . $pattern;
        $segments = array_filter(
            explode('/', $fullPattern),
            fn($segment) => $segment !== ''
        );

        $segments = array_map(function ($segment) {
            if (!str_starts_with($segment, '{')) {
                return $segment;
            }
            if (str_ends_with($segment, '*}')) {
                return self::NODE_WILDCARD;
            }
            if (str_ends_with($segment, '}')) {
                return self::NODE_PARAMETER;
            }
        }, $segments);

        $currentNode = &$this->routes['tree'];
        foreach ($segments as $node) {
            $currentNode[$node] ??= [];
            $currentNode = &$currentNode[$node];
        }

        $currentNode[self::NODE_ROUTES] ??= [];
        $currentNode[self::NODE_ROUTES][$this->routeOrder] = [
            'methods'    => $methods,
            'pattern'    => $fullPattern,
            'handler'    => $handler,
            'middleware' => $this->context['middleware'],
            'rules'      => $this->context['rules'],
        ];

        if ($name !== null) {
            $this->routes['names'][$name] = $fullPattern;
        }

        $this->routeOrder++;
    }

    public function get(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['GET'], $pattern, $handler, $name);
    }
    public function post(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['POST'], $pattern, $handler, $name);
    }
    public function put(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['PUT'], $pattern, $handler, $name);
    }
    public function delete(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['DELETE'], $pattern, $handler, $name);
    }
    public function patch(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['PATCH'], $pattern, $handler, $name);
    }
    public function options(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['OPTIONS'], $pattern, $handler, $name);
    }
    public function form(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['GET', 'POST'], $pattern, $handler, $name);
    }
    public function any(string $pattern, mixed $handler, ?string $name = null): void
    {
        $this->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $pattern, $handler, $name);
    }

    public function addMiddleware(mixed $middleware): void
    {
        $this->context['middleware'][] = $middleware;
    }
    public function addParameterRules(array $rules): void
    {
        $this->context['rules'] += $rules;
    }

    public function addGroup(string $prefix, callable $callback): void
    {
        $previousContext = $this->context;
        $this->context['prefix'] .= $prefix;
        $callback($this);
        $this->context = $previousContext;
    }

    public function dispatch(?string $requestMethod = null, ?string $requestPath = null): int
    {
        $requestMethod    = $requestMethod ?? $_SERVER['REQUEST_METHOD'];
        $requestPath      = $requestPath ?? $this->getRelativeRequestPath();
        $requestPathParts = explode('/', $requestPath);
        $routes           = $this->findMatchingRoutes($requestPath);
        $allowedMethods   = [];

        foreach ($routes as $route) {
            $isWildcard = str_ends_with($route['pattern'], '*}');
            $patternParts = explode('/', $route['pattern']);

            if (
                (!$isWildcard && count($requestPathParts) !== count($patternParts)) ||
                ($isWildcard && count($requestPathParts) < count($patternParts))
            ) {
                continue;
            }

            foreach ($patternParts as $index => $part) {
                if ($part !== $requestPathParts[$index] && !str_starts_with($part, '{')) {
                    continue 2;
                }
            }

            $params = [];
            foreach ($patternParts as $index => $part) {
                if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                    $paramName = trim($part, '{}?*');
                    $params[$paramName] = $requestPathParts[$index];
                }
            }

            $lastParamKey = array_key_last($params);
            $isOptional = str_ends_with($route['pattern'], '?}');

            // If the last parameter is optional and empty, remove it from params
            if ($isOptional && isset($lastParamKey) && empty($params[$lastParamKey])) {
                unset($params[$lastParamKey]);
            }

            // If the last parameter is required (not optional or wildcard) and empty, skip this route
            if (!$isOptional && !$isWildcard && isset($lastParamKey) && empty($params[$lastParamKey])) {
                continue;
            }

            // If the route uses a wildcard parameter, join the remaining path segments into a single value
            if ($isWildcard && isset($lastParamKey)) {
                $params[$lastParamKey] = implode('/', array_slice($requestPathParts, count($patternParts) - 1));
            }

            // If the wildcard parameter is empty after joining, remove it from params
            if ($isWildcard && isset($lastParamKey) && empty($params[$lastParamKey])) {
                unset($params[$lastParamKey]);
            }

            foreach ($route['rules'] as $paramName => $rule) {
                if (!isset($params[$paramName])) {
                    continue;
                }
                if (is_string($rule) && str_starts_with($rule, '#^')) {
                    if (!preg_match($rule, $params[$paramName])) {
                        continue 2;
                    }
                    continue;
                }
                $result = $this->getHandler($rule, [
                    $paramName => $params[$paramName]
                ])();
                if ($result === false) {
                    continue 2;
                }
                if ($result === true) {
                    continue;
                }
                $params[$paramName] = $result;
            }

            $allowedMethods = array_unique(array_merge($allowedMethods, $route['methods']));
            if (!in_array($requestMethod, $route['methods'])) {
                continue;
            }

            $this->lastDispatchedRoute = $route;
            foreach ($route['middleware'] as $middleware) {
                $result = $this->getHandler($middleware)();
                if ($result === false) {
                    return self::DISPATCH_BLOCKED;
                }
            }
            $this->getHandler($route['handler'], $params)();
            return self::DISPATCH_FOUND;
        }

        if (empty($allowedMethods)) {
            ($this->notFoundHandler)($requestMethod, $requestPath);
            $this->lastDispatchedRoute = null;
            return self::DISPATCH_NOT_FOUND;
        } else {
            ($this->methodNotAllowedHandler)($requestMethod, $requestPath, $allowedMethods);
            $this->lastDispatchedRoute = null;
            return self::DISPATCH_NOT_ALLOWED;
        }
    }

    private function getHandler(mixed $handler, array $handlerParams = []): callable
    {
        if (!isset($this->container)) {
            return is_array($handler)
                ? fn() => (new $handler[0])->{$handler[1]}(...array_values($handlerParams))
                : fn() => $handler(...array_values($handlerParams));
        }

        $resolveDependencies = function ($callable) use ($handlerParams) {
            if (is_array($callable)) {
                $reflection = new ReflectionMethod($callable[0], $callable[1]);
            } else {
                $reflection = new ReflectionFunction($callable);
            }

            $resolvedParams = [];
            foreach ($reflection->getParameters() as $param) {
                $paramName = $param->getName();
                $type = $param->getType();

                if (array_key_exists($paramName, $handlerParams)) {
                    continue;
                }

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    $resolvedParams[$paramName] = $this->container->get($typeName);
                }
            }

            return array_merge($resolvedParams, $handlerParams);
        };

        if (is_array($handler)) {
            $instance = $this->container->get($handler[0]);
            $callable = [$instance, $handler[1]];
            $resolvedParams = $resolveDependencies($callable);
            return fn() => $callable(...array_values($resolvedParams));
        }

        $resolvedParams = $resolveDependencies($handler);
        return fn() => $handler(...array_values($resolvedParams));
    }

    private function findMatchingRoutes(string $path): array
    {
        $segments = array_filter(
            explode('/', $path),
            fn($node) => $node !== ''
        );

        $foundRoutes = [];
        $resolve = function ($tree, $segments) use (&$resolve, &$foundRoutes) {
            if (empty($segments) && isset($tree[self::NODE_ROUTES])) {
                $foundRoutes += $tree[self::NODE_ROUTES];
                // Do not return here; parameter nodes at this level may also have routes
            }
            $segment = array_shift($segments);
            // 1. Try explicit/static route first
            if ($segment !== null && isset($tree[$segment])) {
                $resolve($tree[$segment], $segments);
            }
            // 2. Try parameter node at this level (even if segment is null)
            if (isset($tree[self::NODE_PARAMETER])) {
                $resolve($tree[self::NODE_PARAMETER], $segments);
            }
            // 3. Try wildcard node at this level (even if segment is null)
            if (isset($tree[self::NODE_WILDCARD])) {
                $resolve($tree[self::NODE_WILDCARD], []);
            }
        };


        $resolve($this->routes['tree'], $segments);
        ksort($foundRoutes);
        return $foundRoutes;
    }

    public function getRelativeRequestPath(): string
    {
        $uri = urldecode(strtok($_SERVER['REQUEST_URI'], '?'));
        $script = $_SERVER['SCRIPT_NAME'];
        $scriptDir = rtrim(dirname($script), '/\\');

        if ($uri === $script) {
            return '/';
        }

        if (str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        } elseif ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        return $uri === '' ? '/' : $uri;
    }

    public function getRoutes(): array
    {
        $routes = [];
        $list = function ($tree) use (&$list, &$routes) {
            foreach ($tree as $key => $node) {
                if ($key === self::NODE_ROUTES) {
                    foreach ($node as $order => $route) {
                        $route['order'] = $order;
                        $routes[] = $route;
                    }
                } elseif (is_array($node)) {
                    $list($node);
                }
            }
        };
        $list($this->routes['tree']);
        return $routes;
    }

    public function getDispatchedRoute(): ?array
    {
        return $this->lastDispatchedRoute;
    }

    public function getUriFor(string $name, array $params = []): ?string
    {
        $uri = $this->routes['names'][$name] ?? null;
        if (!$uri) return null;

        foreach ($params as $key => $value) {
            $uri = preg_replace('/\{' . preg_quote($key, '/') . '(\*|\?|)\}/', urlencode($value), $uri, -1, $count);
            if (!$count) $query[$key] = $value;
        }

        return isset($query) ? $uri . '?' . http_build_query($query) : $uri;
    }
}
