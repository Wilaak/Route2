<?php

namespace Wilaak\Http;

use Closure;
use ReflectionFunction;
use ReflectionMethod;

class Route2
{
    private array $groupCtx = [
        'prefix'      => '',
        'middleware'  => [],
        'rules'       => [],
    ];

    private int $routeOrder = 0;

    public array $routes = [
        'names' => [],
        'tree'  => [],
    ];

    public const DISPATCH_OK                 = 0;
    public const DISPATCH_NOT_FOUND          = 1;
    public const DISPATCH_METHOD_NOT_ALLOWED = 2;
    public const DISPATCH_MIDDLEWARE_BLOCKED = 3;

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
        $segments = array_filter(
            explode('/', $this->groupCtx['prefix'] . $pattern),
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
            'pattern'    => $this->groupCtx['prefix'] . $pattern,
            'handler'    => $handler,
            'middleware' => $this->groupCtx['middleware'],
            'rules'      => $this->groupCtx['rules'],
        ];

        if ($name !== null) {
            $this->routes['names'][$name] = $this->groupCtx['prefix'] . $pattern;
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
        $this->groupCtx['middleware'][] = $middleware;
    }
    public function addParameterRules(array $rules): void
    {
        $this->groupCtx['rules'] += $rules;
    }

    public function addGroup(string $prefix, callable $callback): void
    {
        $previousContext = $this->groupCtx;
        $this->groupCtx['prefix'] .= $prefix;
        $callback($this);
        $this->groupCtx = $previousContext;
    }

    /**
     * Dispatches the request to the appropriate route handler.
     *
     * @param string|null $requestMethod The HTTP request method (e.g., 'GET', 'POST'). If null, uses $_SERVER['REQUEST_METHOD'].
     * @param string|null $requestPath   The request path to match against routes. If null, uses the relative request path.
     * @return int Returns a dispatch status code:
     *             - self::DISPATCH_OK if a route was successfully dispatched,
     *             - self::DISPATCH_MIDDLEWARE_BLOCKED if middleware blocked the request,
     *             - self::DISPATCH_NOT_FOUND if no route matched,
     *             - self::DISPATCH_METHOD_NOT_ALLOWED if the method is not allowed for the matched route.
     */
    public function dispatch(?string $requestMethod = null, ?string $requestPath = null): int
    {
        $requestMethod = $requestMethod ?? $_SERVER['REQUEST_METHOD'];
        $requestPath = $requestPath ?? $this->getRelativeRequestPath();
        $routes = $this->findMatchingRoutes($requestPath);
        $requestPathParts = explode('/', $requestPath);

        $allowedMethods = [];
        foreach ($routes as $route) {
            $isWildcard = str_ends_with($route['pattern'], '*}');
            $uriParts = explode('/', $route['pattern']);
            if (
                (!$isWildcard && count($requestPathParts) !== count($uriParts)) ||
                ($isWildcard && count($requestPathParts) < count($uriParts))
            ) continue;

            foreach ($uriParts as $index => $part) {
                if ($part !== $requestPathParts[$index] && !str_starts_with($part, '{')) {
                    continue 2;
                }
            }

            $params = [];
            foreach ($uriParts as $index => $part) {
                if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                    $paramName = trim($part, '{}?*');
                    $params[$paramName] = $requestPathParts[$index];
                }
            }

            $lastParamKey = array_key_last($params);
            $isOptional = str_ends_with($route['pattern'], '?}');
            if ($isOptional && isset($lastParamKey) && empty($params[$lastParamKey])) {
                unset($params[$lastParamKey]);
            }
            if (!$isOptional && !$isWildcard && isset($lastParamKey) && empty($params[$lastParamKey])) {
                continue;
            }
            if ($isWildcard) {
                $params[$lastParamKey] = implode('/', array_slice($requestPathParts, count($uriParts) - 1));
            }
            if ($isWildcard && empty($params[$lastParamKey])) {
                unset($params[$lastParamKey]);
            }

            foreach ($this->groupCtx['rules'] as $paramName => $expression) {
                if (!isset($params[$paramName])) {
                    continue;
                }
                if (is_string($expression) && str_starts_with($expression, '#^')) {
                    if (!preg_match($expression, $params[$paramName])) {
                        continue 2;
                    }
                    continue;
                }
                $result = $this->getHandler($expression, [$params[$paramName]])();
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
            foreach ($this->groupCtx['middleware'] as $middleware) {
                $result = $this->getHandler($middleware)();
                if ($result === false) {
                    return self::DISPATCH_MIDDLEWARE_BLOCKED;
                }
            }
            $this->getHandler($route['handler'], $params)();
            return self::DISPATCH_OK;
        }

        if (empty($allowedMethods)) {
            ($this->notFoundHandler)($requestMethod, $requestPath);
            $this->lastDispatchedRoute = null;
            return self::DISPATCH_NOT_FOUND;
        } else {
            ($this->methodNotAllowedHandler)($requestMethod, $requestPath, $allowedMethods);
            $this->lastDispatchedRoute = null;
            return self::DISPATCH_METHOD_NOT_ALLOWED;
        }
    }

    private function getHandler(mixed $handler, array $params = []): callable
    {
        if (empty($this->container)) {
            return is_array($handler)
                ? fn() => (new $handler[0])->{$handler[1]}(...$params)
                : fn() => $handler(...$params);
        }

        $resolveDependencies = function ($callable) use ($params) {
            $reflection = is_array($callable)
                ? new ReflectionMethod($callable[0], $callable[1])
                : new ReflectionFunction($callable);

            $resolvedParams = [];
            foreach ($reflection->getParameters() as $index => $param) {
                $type = $param->getType();
                if ($type && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    // Check if $params contains an instance of the required class type
                    $found = false;
                    foreach ($params as $value) {
                        if (is_object($value) && is_a($value, $typeName)) {
                            $resolvedParams[] = $value;
                            $found = true;
                            break;
                        }
                    }
                    if ($found) {
                        continue;
                    }
                    // Otherwise, get from container
                    $resolvedParams[] = $this->container->get($typeName);
                    continue;
                }

                if (array_key_exists($index, $params)) {
                    $resolvedParams[] = $params[$index];
                    continue;
                }

                if ($param->isDefaultValueAvailable()) {
                    $resolvedParams[] = $param->getDefaultValue();
                    continue;
                }

                $resolvedParams[] = null;
            }
            return $resolvedParams + $params;
        };

        if (is_array($handler)) {
            // [ClassName::class, 'method']
            $instance = is_string($handler[0]) ? $this->container->get($handler[0]) : $handler[0];
            $callable = [$instance, $handler[1]];
            $resolvedParams = $resolveDependencies($callable);
            return fn() => call_user_func_array($callable, $resolvedParams);
        }

        // Callable (closure, function, invokable object)
        $resolvedParams = $resolveDependencies($handler);
        return fn() => call_user_func_array($handler, $resolvedParams);
    }

    /**
     * Finds and returns all routes that match the given path.
     *
     * @param string $path The path to match against the route tree.
     * @return array An array of matching routes with their order.
     */
    public function findMatchingRoutes(string $path): array
    {
        $segments = array_filter(
            explode('/', $path),
            fn($node) => $node !== ''
        );

        $foundRoutes = [];
        $resolve = function ($tree, $segments) use (&$resolve, &$foundRoutes) {
            if (empty($segments) && isset($tree[self::NODE_ROUTES])) {
                return $foundRoutes += $tree[self::NODE_ROUTES];
            }
            $segment = array_shift($segments);
            if (isset($tree[self::NODE_WILDCARD])) {
                $resolve($tree[self::NODE_WILDCARD], []);
            }
            if (isset($tree[self::NODE_PARAMETER])) {
                $resolve($tree[self::NODE_PARAMETER], $segments);
            }
            if (isset($tree[$segment])) {
                $resolve($tree[$segment], $segments);
            }
        };

        $resolve($this->routes['tree'], $segments);
        ksort($foundRoutes);
        return $foundRoutes;
    }

    /**
     * Returns the relative request path, removing the script name or script directory from the beginning.
     *
     * Example:
     *   If SCRIPT_NAME is '/index.php' and REQUEST_URI is '/index.php/foo/bar?baz=1',
     *   this returns '/foo/bar'.
     *
     * @return string The relative request path.
     */
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

    /**
     * Retrieves all routes from the internal route tree as a flat array.
     *
     * @return array An array of routes.
     */
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

    /**
     * Retrieves information about the dispatched route.
     *
     * @return array|null An associative array containing the dispatched route information,
     *                    or null if no route has been matched.
     */
    public function getDispatchedRoute(): ?array
    {
        return $this->lastDispatchedRoute;
    }

    /**
     * Gets the URI for a named route, replacing parameters with their values.
     *
     * @param string $name   The name of the route.
     * @param array  $params Optional parameters to replace or append as query parameters.
     * @return string|null   The generated URL, or null if the route name does not exist.
     */
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
