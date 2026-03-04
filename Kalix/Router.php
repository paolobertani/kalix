<?php
declare(strict_types=1);

namespace Kalix;

use RuntimeException;

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
        $route = $this->compile($pattern);
        $route['target'] = $target;

        $this->routes[] = $route;
    }



    /*
     * Match path.
     *
     * Matches a path and classifies it as matched, malformed or not found.
     */

    public function resolve(string $path): array
    {
        $path = $this->normalizePath($path);
        $pathSegments = $this->splitPath($path);
        $malformed = false;

        foreach ($this->routes as $r) {
            $result = $this->evaluateSegments($r['segments'], $pathSegments);
            if ($result['status'] === 'matched') {
                return [
                    'status' => 'matched',
                    'match' => [
                        'target' => $r['target'],
                        'params' => $result['params'],
                        'raw_params' => $result['raw_params'],
                        'keys' => $r['keys'],
                        'pattern' => $r['pattern'],
                        'has_lang' => $r['has_lang'],
                        'route' => $r,
                    ],
                ];
            }

            if ($result['status'] === 'malformed') {
                $malformed = true;
            }
        }

        if ($malformed) {
            return ['status' => 'malformed'];
        }

        return ['status' => 'not_found'];
    }



    /*
     * Match without language.
     *
     * Matches the path against routes where the `@lang` segment is omitted.
     */

    public function matchWithoutLanguage(string $path): ?array
    {
        $path = $this->normalizePath($path);
        $pathSegments = $this->splitPath($path);

        foreach ($this->routes as $r) {
            if ($r['has_lang'] !== true) {
                continue;
            }

            $langIndex = $r['lang_index'] ?? null;
            if (is_int($langIndex) && array_key_exists($langIndex, $pathSegments)) {
                $candidate = (string)$pathSegments[$langIndex];
                if (preg_match('/^[a-zA-Z]{2}$/', $candidate) === 1) {
                    continue;
                }
            }

            $result = $this->evaluateSegments($r['segments_without_lang'], $pathSegments);
            if ($result['status'] !== 'matched') {
                continue;
            }

            return [
                'target' => $r['target'],
                'params' => $result['params'],
                'raw_params' => $result['raw_params'],
                'keys' => $r['keys_without_lang'],
                'pattern' => $r['pattern'],
                'route' => $r,
            ];
        }

        return null;
    }



    /*
     * Build localized path.
     *
     * Rebuilds a route path inserting the provided language at `@lang`.
     */

    public function buildPathWithLanguage(array $matchWithoutLanguage, string $lang): string
    {
        $route = $matchWithoutLanguage['route'] ?? null;
        if (!is_array($route) || ($route['has_lang'] ?? false) !== true) {
            throw new RuntimeException('Cannot build localized path without route metadata.');
        }

        $rawParams = is_array($matchWithoutLanguage['raw_params'] ?? null) ? $matchWithoutLanguage['raw_params'] : [];

        $parts = [];
        $skippingOptionals = false;

        foreach ($route['segments'] as $segment) {
            if ($segment['kind'] === 'static') {
                if ($skippingOptionals) {
                    throw new RuntimeException('Invalid route with static segment after optional parameters.');
                }

                $parts[] = $segment['value'];
                continue;
            }

            if ($segment['name'] === 'lang') {
                $parts[] = rawurlencode($lang);
                continue;
            }

            $value = $rawParams[$segment['name']] ?? null;
            if ($value === null) {
                if ($segment['optional'] === true) {
                    $skippingOptionals = true;
                    continue;
                }

                throw new RuntimeException('Missing required route parameter: ' . $segment['name']);
            }

            if ($skippingOptionals) {
                throw new RuntimeException('Invalid optional parameter state while rebuilding route.');
            }

            $parts[] = rawurlencode($this->stringifyValue($value));
        }

        return $parts === [] ? '/' : '/' . implode('/', $parts);
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
     * Parses and validates route pattern segments and metadata.
     */

    private function compile(string $pattern): array
    {
        $rawSegments = $this->splitPath($pattern);
        $segments = [];
        $keys = [];
        $optionalStarted = false;
        $langPlaceholders = 0;
        $langIndex = null;

        foreach ($rawSegments as $index => $rawSegment) {
            $segment = $this->parseSegment($rawSegment);

            if ($segment['kind'] === 'param') {
                if (in_array($segment['name'], $keys, true)) {
                    throw new RuntimeException('Duplicated route parameter: ' . $segment['name']);
                }

                $keys[] = $segment['name'];

                if ($segment['name'] === 'lang') {
                    $langPlaceholders++;
                    $langIndex = $index;
                }

                if ($segment['optional'] === true) {
                    $optionalStarted = true;
                } elseif ($optionalStarted) {
                    throw new RuntimeException('All parameters after an optional one must also be optional.');
                }
            } elseif ($optionalStarted) {
                throw new RuntimeException('Optional parameters must be trailing route segments.');
            }

            $segments[] = $segment;
        }

        if ($langPlaceholders > 1) {
            throw new RuntimeException('A route can contain at most one @lang placeholder.');
        }

        $hasLang = $langPlaceholders === 1;
        $segmentsWithoutLang = [];
        $keysWithoutLang = [];

        foreach ($segments as $segment) {
            if ($segment['kind'] === 'param' && $segment['name'] === 'lang') {
                continue;
            }

            $segmentsWithoutLang[] = $segment;
            if ($segment['kind'] === 'param') {
                $keysWithoutLang[] = $segment['name'];
            }
        }

        return [
            'pattern' => $pattern,
            'segments' => $segments,
            'segments_without_lang' => $segmentsWithoutLang,
            'keys' => $keys,
            'keys_without_lang' => $keysWithoutLang,
            'has_lang' => $hasLang,
            'lang_index' => $langIndex,
        ];
    }



    /*
     * Parse route segment.
     *
     * Converts one route segment into static or typed-parameter metadata.
     */

    private function parseSegment(string $segment): array
    {
        if (preg_match('/^@([a-zA-Z_][a-zA-Z0-9_]*)(?::(\??)(int|float|string|boolean))?$/', $segment, $m) !== 1) {
            if (str_starts_with($segment, '@')) {
                throw new RuntimeException('Invalid route parameter syntax: ' . $segment);
            }

            return [
                'kind' => 'static',
                'value' => $segment,
            ];
        }

        $name = $m[1];
        $hasType = isset($m[3]) && $m[3] !== '';
        $optional = $hasType && (($m[2] ?? '') === '?');
        $type = $hasType ? (string)$m[3] : 'string';

        if ($name === 'lang' && $hasType) {
            throw new RuntimeException('The @lang placeholder cannot define an explicit type.');
        }

        return [
            'kind' => 'param',
            'name' => $name,
            'type' => $name === 'lang' ? 'lang' : $type,
            'optional' => $optional,
            'typed' => $hasType,
        ];
    }



    /*
     * Split path.
     *
     * Splits a normalized path into URL-decoded segments.
     */

    private function splitPath(string $path): array
    {
        if ($path === '/') {
            return [];
        }

        $parts = explode('/', trim($path, '/'));
        $decoded = [];

        foreach ($parts as $part) {
            $decoded[] = urldecode($part);
        }

        return $decoded;
    }



    /*
     * Evaluate segments.
     *
     * Matches path segments against route metadata.
     */

    private function evaluateSegments(array $routeSegments, array $pathSegments): array
    {
        $params = [];
        $rawParams = [];
        $routeCount = count($routeSegments);
        $pathCount = count($pathSegments);

        $i = 0;
        $j = 0;

        while ($i < $routeCount) {
            $segment = $routeSegments[$i];

            if ($segment['kind'] === 'static') {
                if ($j >= $pathCount) {
                    return ['status' => 'malformed'];
                }

                if ($pathSegments[$j] !== $segment['value']) {
                    return ['status' => 'not_found'];
                }

                $i++;
                $j++;
                continue;
            }

            if ($j >= $pathCount) {
                if ($segment['optional'] !== true) {
                    return ['status' => 'malformed'];
                }

                while ($i < $routeCount) {
                    $missing = $routeSegments[$i];
                    if ($missing['kind'] !== 'param' || $missing['optional'] !== true) {
                        return ['status' => 'malformed'];
                    }

                    $params[$missing['name']] = null;
                    $rawParams[$missing['name']] = null;
                    $i++;
                }

                return [
                    'status' => 'matched',
                    'params' => $params,
                    'raw_params' => $rawParams,
                ];
            }

            $rawValue = $pathSegments[$j];
            $validated = $this->validateParamValue($segment, $rawValue);
            if ($validated['status'] !== 'matched') {
                return ['status' => 'malformed'];
            }

            $params[$segment['name']] = $validated['value'];
            $rawParams[$segment['name']] = $rawValue;

            $i++;
            $j++;
        }

        if ($j < $pathCount) {
            return ['status' => 'not_found'];
        }

        return [
            'status' => 'matched',
            'params' => $params,
            'raw_params' => $rawParams,
        ];
    }



    /*
     * Validate parameter value.
     *
     * Validates and casts one path segment according to route type metadata.
     */

    private function validateParamValue(array $segment, string $raw): array
    {
        if (($segment['optional'] ?? false) === true && $raw === 'null') {
            return [
                'status' => 'matched',
                'value' => null,
            ];
        }

        if ($segment['type'] === 'lang') {
            if (preg_match('/^[a-z]{2}$/', $raw) !== 1) {
                return ['status' => 'malformed'];
            }

            return [
                'status' => 'matched',
                'value' => $raw,
            ];
        }

        if ($segment['type'] === 'string' && ($segment['typed'] ?? false) === true) {
            return $this->validateBase64UrlString($raw);
        }

        return match ($segment['type']) {
            'int' => $this->validateInteger($raw),
            'float' => $this->validateFloat($raw),
            'boolean' => $this->validateBoolean($raw),
            default => [
                'status' => 'matched',
                'value' => $raw,
            ],
        };
    }



    /*
     * Validate integer.
     *
     * Validates integer payload and returns casted value.
     */

    private function validateInteger(string $raw): array
    {
        if (preg_match('/^-?[0-9]+$/', $raw) !== 1) {
            return ['status' => 'malformed'];
        }

        return [
            'status' => 'matched',
            'value' => (int)$raw,
        ];
    }



    /*
     * Validate float.
     *
     * Validates float payload and returns casted value.
     */

    private function validateFloat(string $raw): array
    {
        if (!is_numeric($raw)) {
            return ['status' => 'malformed'];
        }

        return [
            'status' => 'matched',
            'value' => (float)$raw,
        ];
    }



    /*
     * Validate boolean.
     *
     * Validates boolean payload and returns casted value.
     */

    private function validateBoolean(string $raw): array
    {
        if ($raw !== '0' && $raw !== '1') {
            return ['status' => 'malformed'];
        }

        return [
            'status' => 'matched',
            'value' => $raw === '1',
        ];
    }



    /*
     * Validate base64url string.
     *
     * Validates and decodes a base64url payload.
     */

    private function validateBase64UrlString(string $raw): array
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
            return ['status' => 'malformed'];
        }

        $encoded = strtr($raw, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)) {
            return ['status' => 'malformed'];
        }

        return [
            'status' => 'matched',
            'value' => $decoded,
        ];
    }



    /*
     * Stringify value.
     *
     * Converts scalar route values to string form.
     */

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string)$value;
    }
}
