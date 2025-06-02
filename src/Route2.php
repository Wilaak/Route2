<?php

namespace Wilaak\Http;

use Closure;

/**
 * A simple routing library for PHP web services.
 */
class Route2
{
    public string $requestMethod;
    public string $requestUri;
    private array $requestUriParts;

    private array $ctx = [
        'groupPrefix'      => '',
        'beforeMiddleware' => [],
        'afterMiddleware'  => [],
        'paramExpressions' => [],
    ];

    public array $allowedMethods = [];

    private Closure $handlerHook;

    public function __construct(?string $requestMethod = null, ?string $requestUri = null)
    {
        $this->requestMethod = $requestMethod ?? $_SERVER['REQUEST_METHOD'];
        $this->requestUri = $requestUri ?? $this->getRelativeRequestUri();
        $this->requestUriParts = explode('/', $this->requestUri);
    }

    public function match(array $methods, string $uri, mixed $handler): void
    {
        $isWildcard = str_ends_with($uri, '*}');
        $uriParts = explode('/', $this->ctx['groupPrefix'] . $uri);
        if (
            (!$isWildcard && count($this->requestUriParts) !== count($uriParts)) ||
            ($isWildcard && count($this->requestUriParts) < count($uriParts))
        ) return;

        foreach ($uriParts as $index => $part) {
            if ($part !== $this->requestUriParts[$index] && !str_starts_with($part, '{')) {
                return;
            }
        }

        $params = [];
        foreach ($uriParts as $index => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $paramName = trim($part, '{}?*');
                $params[$paramName] = $this->requestUriParts[$index];
            }
        }

        $lastParam = array_key_last($params);
        $isOptional = str_ends_with($uri, '?}');
        if ($isOptional && isset($lastParam) && empty($params[$lastParam])) {
            unset($params[$lastParam]);
        }
        if (!$isOptional && !$isWildcard && isset($lastParam) && empty($params[$lastParam])) {
            return;
        }
        if ($isWildcard) {
            $params[$lastParam] = implode('/', array_slice($this->requestUriParts, count($uriParts) - 1));
        }
        if ($isWildcard && empty($params[$lastParam])) {
            unset($params[$lastParam]);
        }

        foreach ($this->ctx['paramExpressions'] as $paramName => $expression) {
            if (!isset($params[$paramName])) {
                continue;
            }
            if (is_string($expression) && str_starts_with($expression, '#^')) {
                if (!preg_match($expression, $params[$paramName])) {
                    return;
                }
                continue;
            }
            $result = $this->getHandler($expression, [$params[$paramName]])();
            if ($result === false) {
                return;
            }
            if ($result === true) {
                continue;
            }
            $params[$paramName] = $result;
        }

        $this->allowedMethods = array_unique(array_merge($this->allowedMethods, $methods));
        if (!in_array($this->requestMethod, $methods)) {
            return;
        }

        foreach ($this->ctx['beforeMiddleware'] as $middleware) {
            $result = $this->getHandler($middleware)();
            if ($result === false) {
                exit;
            }
        }
        $this->getHandler($handler, $params)();
        foreach ($this->ctx['afterMiddleware'] as $middleware) {
            $result = $this->getHandler($middleware)();
            if ($result === false) {
                exit;
            }
        }
        exit;
    }

    public function get(string $uri, mixed $handler): void { $this->match(['GET'], $uri, $handler); }
    public function post(string $uri, mixed $handler): void { $this->match(['POST'], $uri, $handler); }
    public function put(string $uri, mixed $handler): void { $this->match(['PUT'], $uri, $handler); }
    public function delete(string $uri, mixed $handler): void { $this->match(['DELETE'], $uri, $handler); }
    public function patch(string $uri, mixed $handler): void { $this->match(['PATCH'], $uri, $handler); }
    public function options(string $uri, mixed $handler): void { $this->match(['OPTIONS'], $uri, $handler); }
    public function form(string $uri, mixed $handler): void { $this->match(['GET', 'POST'], $uri, $handler); }
    public function any(string $uri, mixed $handler): void { $this->match(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $uri, $handler); }
    public function before(mixed $middleware): void { $this->ctx['beforeMiddleware'][] = $middleware; }
    public function after(mixed $middleware): void { $this->ctx['afterMiddleware'][] = $middleware; }
    public function expression(array $expression): void { $this->ctx['paramExpressions'] += $expression; }

    public function group(string $prefix, callable $callback): void
    {
        if ($prefix !== '') {
            $uriParts = explode('/', $this->ctx['groupPrefix'] . $prefix);
            foreach ($uriParts as $index => $part) {
                if (
                    isset($this->requestUriParts[$index])
                    && $part !== $this->requestUriParts[$index]
                    && !str_starts_with($part, '{')
                ) return;
            }
        }
        $previousContext = $this->ctx;
        if ($prefix !== '') {
            $this->ctx['groupPrefix'] .= $prefix;
        }
        $callback($this);
        $this->ctx = $previousContext;
    }

    private function getHandler(mixed $handler, array $params = []): callable
    {
        if (isset($this->handlerHook)) {
            return ($this->handlerHook)($handler, $params);
        }
        return is_array($handler)
            ? fn() => (new $handler[0])->{$handler[1]}(...$params)
            : fn() => $handler(...$params);
    }

    public function setHandlerHook(Closure $hook): void
    {
        $this->handlerHook = $hook;
    }

    public function getRelativeRequestUri(): string
    {
        $uri = urldecode(strtok($_SERVER['REQUEST_URI'], '?'));
        $script = $_SERVER['SCRIPT_NAME'];
        $scriptDir = rtrim(dirname($script), '/\\');
        if ($uri === $script) {
            $uri = '/';
        } elseif (str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        } elseif ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }
        return $uri === '' ? '/' : $uri;
    }
}
