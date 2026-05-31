<?php
// src/Core/Router.php

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $globalMiddlewares = [];

    public function add(string $method, string $path, $handler, array $middlewares = []): void
    {
        // Normalize path
        $path = '/' . trim($path, '/');
        
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function get(string $path, $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    public function addGlobalMiddleware($middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];
        
        // Parse path ignoring query parameters
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        
        // Dynamic base path correction (XAMPP subfolder / public_html / cPanel root compatible)
        $scriptName = $_SERVER['SCRIPT_NAME']; // E.g., /chatbot/index.php
        $basePath = dirname($scriptName);      // E.g., /chatbot
        
        // Normalize base path to avoid double slashes
        $basePath = rtrim($basePath, '/\\');
        
        if (!empty($basePath) && strpos($requestPath, $basePath) === 0) {
            $requestPath = substr($requestPath, strlen($basePath));
        }
        
        $requestPath = '/' . trim($requestPath, '/');

        // Look for matching route
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->matchPath($route['path'], $requestPath, $params)) {
                
                // Execute Global Middlewares
                foreach ($this->globalMiddlewares as $middleware) {
                    if (!$middleware->handle()) {
                        return; // Blocked by global middleware
                    }
                }

                // Execute Route-specific Middlewares
                foreach ($route['middlewares'] as $middlewareClass) {
                    $middleware = new $middlewareClass();
                    if (!$middleware->handle()) {
                        return; // Blocked by route middleware
                    }
                }

                // Execute Handler
                $handler = $route['handler'];
                if (is_callable($handler)) {
                    call_user_func_array($handler, $params);
                } elseif (is_array($handler) && count($handler) === 2) {
                    $controllerClass = $handler[0];
                    $action = $handler[1];
                    
                    $controller = new $controllerClass();
                    call_user_func_array([$controller, $action], $params);
                } else {
                    throw new \Exception("Invalid route handler format.");
                }
                return;
            }
        }

        // No route matched
        Response::json(['error' => 'Not Found', 'path' => $requestPath], 404);
    }

    private function matchPath(string $routePath, string $requestPath, ?array &$params = []): bool
    {
        $params = [];
        
        // Replace dynamic parameter markers: {id} or {name} with matching regex rules
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_\-]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return true;
        }

        return false;
    }
}
