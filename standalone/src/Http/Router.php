<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http;

/**
 * Schlanker manueller Router (kein Framework) - Vorbild ist die
 * Routentabelle aus merlin-nextcloud/appinfo/routes.php, nur direkt gegen
 * eine Regex statt gegen NCs App-Routing aufgelöst.
 */
final class Router {
    private array $routes = [];

    /**
     * @param callable(Request): ?Response $handler Gibt eine Response zurück,
     *        oder wirft/liefert 4xx über die Middleware
     * @param array<callable(Request): ?Response> $middleware Laufen in Reihenfolge vor dem Handler;
     *        die erste Middleware, die eine Response zurückgibt, beendet die Kette
     * @param array<string, string> $requirements Regex je Platzhalter wie in
     *        Nextclouds routes.php (sonst ein Pfadsegment); passt er nicht,
     *        gilt die Route als nicht gefunden
     */
    public function add(string $method, string $path, callable $handler, array $middleware = [], array $requirements = []): void {
        $paramNames = [];
        $pattern = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$paramNames, $requirements) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>' . ($requirements[$m[1]] ?? '[^/]+') . ')';
        }, $path);

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Registrierte Routen (für tools/test-route-parity.php).
     *
     * @return list<array{method: string, path: string}>
     */
    public function routes(): array {
        return array_map(static fn (array $r) => ['method' => $r['method'], 'path' => $r['path']], $this->routes);
    }

    public function dispatch(Request $request): Response {
        $method = $request->method();
        $path = rtrim($request->path(), '/') ?: '/';

        $pathMatchedButWrongMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $pathMatchedButWrongMethod = true;
                continue;
            }

            $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
            $request->setRouteParams($params);

            foreach ($route['middleware'] as $middleware) {
                $result = $middleware($request);
                if ($result !== null) {
                    return $result;
                }
            }

            return ($route['handler'])($request);
        }

        return Response::json(
            ['error' => $pathMatchedButWrongMethod ? 'Method not allowed' : 'Not found'],
            $pathMatchedButWrongMethod ? 405 : 404
        );
    }
}
