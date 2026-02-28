<?php
declare(strict_types=1);

namespace Kalix;

final class Router
{
    private array $routes = [];



    /*
     * Add route.
     *
     * Compiles and stores a route pattern with its dispatch target.
     */

    public function add(string $pattern, string $target): void
    {
        $pattern = $this->normalizePath($pattern);
        [$regex, $keys] = $this->compile($pattern);

        $this->routes[] = [
            'pattern' => $pattern,
            'target'  => $target,
            'regex'   => $regex,
            'keys'    => $keys,
        ];
    }



    /*
     * Match path.
     *
     * Matches a path against registered routes and returns params on success.
     */

    public function match(string $path): ?array
    {
        $path = $this->normalizePath($path);

        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                $params = [];
                foreach ($r['keys'] as $k) {
                    if (isset($m[$k])) {
                        $params[$k] = urldecode((string)$m[$k]);
                    }
                }

                return [
                    'target' => $r['target'],
                    'params' => $params,
                    'keys' => $r['keys'],
                    'pattern' => $r['pattern'],
                ];
            }
        }

        return null;
    }



    /*
     * Normalize path.
     *
     * Converts a raw path to canonical format used by the router.
     */

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }



    /*
     * Compile route pattern.
     *
     * Converts placeholder segments into a regular expression.
     */

    private function compile(string $pattern): array
    {
        $keys = [];
        if ($pattern === '/') {
            return ['#^/$#u', $keys];
        }

        $segments = explode('/', trim($pattern, '/'));
        $compiled = [];

        foreach ($segments as $segment) {
            if (preg_match('/^@([a-zA-Z_][a-zA-Z0-9_]*)$/', $segment, $m) === 1) {
                $keys[] = $m[1];
                $compiled[] = '(?P<' . $m[1] . '>[^/]+)';
                continue;
            }

            $compiled[] = preg_quote($segment, '#');
        }

        $regex = '#^/' . implode('/', $compiled) . '$#u';
        return [$regex, $keys];
    }
}
