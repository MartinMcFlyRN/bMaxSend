<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string, regex:string, params:array, handler:array, opts:array}> */
    private array $routes = [];

    /**
     * @param array $opts  public: no login required; roles: allowed roles;
     *                     tracking: also served on alias domains; csrf: false to skip check
     */
    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'opts' => $opts,
        ];
    }

    public function get(string $p, array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }

    public function dispatch(string $method, string $path, bool $onAliasDomain): void
    {
        $method = strtoupper($method);
        $allowed = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            if ($r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
                $allowed = true;
                continue;
            }
            $opts = $r['opts'];
            // Alias (tracking) domains only serve the public email endpoints.
            if ($onAliasDomain && empty($opts['tracking'])) {
                throw new HttpException(404);
            }
            if (empty($opts['public'])) {
                if (!Auth::user()) {
                    if ($method === 'GET') {
                        Session::set('intended', $_SERVER['REQUEST_URI'] ?? '/');
                    }
                    redirect('/login');
                }
                if (!empty($opts['roles']) && !Auth::is(...$opts['roles'])) {
                    throw new HttpException(403);
                }
            }
            if ($method === 'POST' && ($opts['csrf'] ?? true)) {
                Csrf::check();
            }
            $args = [];
            foreach ($r['params'] as $i => $name) {
                $args[$name] = urldecode($m[$i + 1]);
            }
            [$class, $action] = $r['handler'];
            $controller = new $class();
            $controller->$action(...array_values($args));
            return;
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
