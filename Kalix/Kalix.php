<?php
declare(strict_types=1);

namespace Kalix;

use ReflectionMethod;
use RuntimeException;
use Throwable;

final class Kalix
{
    private string $rootPath;
    private string $appPath;
    private Router $router;
    private Router $routerWithoutLang;
    private array $locales = [];
    private string $fallbackLang = 'en';



    /*
     * Construct Kalix.
     *
     * Initializes autoloading, locale catalogues, routes and config.
     */

    public function __construct(array $cfg)
    {
        $this->rootPath = rtrim((string)($cfg['root'] ?? dirname(__DIR__)), '/');
        $this->appPath = rtrim((string)($cfg['app'] ?? ($this->rootPath . '/app')), '/');

        Autoload::register(__DIR__, $this->appPath);

        $this->router = new Router();
        $this->routerWithoutLang = new Router();

        $this->loadDatabaseConfig();
        $this->loadLocales();
        $this->loadRoutes();
    }



    /*
     * Register route.
     *
     * Registers a localized route and its unlocalized variant.
     */

    public function route(string $pattern, string $target): self
    {
        $this->router->add($pattern, $target);

        $unlocalized = $this->stripLangPlaceholder($pattern);
        if ($unlocalized !== null) {
            $this->routerWithoutLang->add($unlocalized, $target);
        }

        return $this;
    }



    /*
     * Run application.
     *
     * Resolves language, matches route, dispatches controller or redirects.
     */

    public function run(): void
    {
        $path = $this->requestPath();
        $query = $this->requestQuery();

        try {
            $localized = $this->router->match($path);

            if ($localized !== null && $this->hasSupportedLang($localized)) {
                $lang = strtolower((string)$localized['params']['lang']);
                $this->persistLanguage($lang);
                $this->dispatch($localized, $lang);
                return;
            }

            [$missingLangMatch, $basePath] = $this->matchWithoutLanguage($path);
            if ($missingLangMatch !== null) {
                $lang = $this->resolvePreferredLanguage();
                $this->persistLanguage($lang);
                $this->redirect($this->buildLocalizedPath($basePath, $lang), $query);
                return;
            }

            $this->send404();
        } catch (Throwable $e) {
            if (PHP_SAPI === 'cli') {
                throw $e;
            }

            http_response_code(500);
            echo '500 Internal Server Error';
        }
    }



    /*
     * Load route files.
     *
     * Loads every `app/routes/*.php` file and registers array-defined routes.
     */

    private function loadRoutes(): void
    {
        $files = glob($this->appPath . '/routes/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $kalix = $this;
            $result = require $file;

            if (is_array($result)) {
                foreach ($result as $pattern => $target) {
                    if (!is_string($pattern) || !is_string($target)) {
                        continue;
                    }

                    $this->route($pattern, $target);
                }

                continue;
            }

            if ($result instanceof \Closure) {
                $result($this);
            }
        }
    }



    /*
     * Load locales.
     *
     * Reads locale dictionaries from `app/locale/*.php`.
     */

    private function loadLocales(): void
    {
        $files = glob($this->appPath . '/locale/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $lang = strtolower((string)pathinfo($file, PATHINFO_FILENAME));
            if (!preg_match('/^[a-z]{2}$/', $lang)) {
                continue;
            }

            $data = require $file;
            $this->locales[$lang] = is_array($data) ? $data : [];
        }

        if ($this->locales === []) {
            $this->locales['en'] = ['lang' => 'en'];
        }

        $keys = array_keys($this->locales);
        $this->fallbackLang = in_array('en', $keys, true) ? 'en' : (string)$keys[0];
    }



    /*
     * Load database config.
     *
     * Loads optional db config from `app/config/database.php`.
     */

    private function loadDatabaseConfig(): void
    {
        $cfg = [];
        $file = $this->appPath . '/config/database.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }

        $cfg = $this->mergeSecretsIntoDbConfig($cfg);
        if ($cfg !== []) {
            Registry::set('/cfg/db', $cfg);
        }
    }



    /*
     * Merge secrets db config.
     *
     * Fills missing DB connection keys using `PRIVATE/secrets.php` constants.
     */

    private function mergeSecretsIntoDbConfig(array $cfg): array
    {
        $fallback = $this->readSecretsDbFallback();
        if ($fallback === []) {
            if ($cfg === []) {
                return [];
            }

            if ($this->isSingleDbConfig($cfg)) {
                return $this->normalizeDbConnection($cfg);
            }

            $normalized = [];
            foreach ($cfg as $name => $connection) {
                if (!is_array($connection)) {
                    continue;
                }

                $normalized[$name] = $this->normalizeDbConnection($connection);
            }

            return $normalized;
        }

        if ($cfg === []) {
            return ['default' => $fallback];
        }

        if ($this->isSingleDbConfig($cfg)) {
            return $this->mergeConnectionWithFallback($cfg, $fallback);
        }

        $merged = [];
        foreach ($cfg as $name => $connection) {
            if (!is_array($connection)) {
                continue;
            }

            $merged[$name] = $this->mergeConnectionWithFallback($connection, $fallback);
        }

        return $merged !== [] ? $merged : ['default' => $fallback];
    }



    /*
     * Read secrets fallback.
     *
     * Loads DB fallback values from constants in `PRIVATE/secrets.php`.
     */

    private function readSecretsDbFallback(): array
    {
        $secretsFile = $this->rootPath . '/PRIVATE/secrets.php';
        if (!is_file($secretsFile)) {
            return [];
        }

        require_once $secretsFile;

        $map = [
            'host' => 'DB_HOST',
            'user' => 'DB_USER',
            'pass' => 'DB_PASS',
            'name' => 'DB_NAME',
            'port' => 'DB_PORT',
            'socket' => 'DB_SOCK',
            'charset' => 'DB_CHARSET',
        ];

        $fallback = [];
        foreach ($map as $key => $constant) {
            if (!defined($constant)) {
                continue;
            }

            $fallback[$key] = constant($constant);
        }

        return $this->normalizeDbConnection($fallback);
    }



    /*
     * Detect single db config.
     *
     * Returns true when config is one connection instead of named connections.
     */

    private function isSingleDbConfig(array $cfg): bool
    {
        $keys = ['host', 'user', 'pass', 'name', 'port', 'socket', 'sock', 'charset'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $cfg)) {
                return true;
            }
        }

        return false;
    }



    /*
     * Normalize db connection.
     *
     * Normalizes aliases and scalar types for one DB connection array.
     */

    private function normalizeDbConnection(array $cfg): array
    {
        if (isset($cfg['sock']) && !isset($cfg['socket'])) {
            $cfg['socket'] = $cfg['sock'];
        }

        unset($cfg['sock']);

        if (isset($cfg['port'])) {
            $cfg['port'] = (int)$cfg['port'];
        }

        return $cfg;
    }



    /*
     * Merge connection with fallback.
     *
     * Applies fallback values only when connection values are unavailable.
     */

    private function mergeConnectionWithFallback(array $connection, array $fallback): array
    {
        $connection = $this->normalizeDbConnection($connection);
        $fallback = $this->normalizeDbConnection($fallback);

        foreach ($fallback as $key => $value) {
            if (!$this->hasDbValue($connection, $key)) {
                $connection[$key] = $value;
            }
        }

        return $connection;
    }



    /*
     * Check db value availability.
     *
     * Returns true when a DB config key is present and usable.
     */

    private function hasDbValue(array $connection, string $key): bool
    {
        if (!array_key_exists($key, $connection)) {
            return false;
        }

        $value = $connection[$key];
        if ($value === null) {
            return false;
        }

        if ($key === 'port') {
            return (int)$value > 0;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }



    /*
     * Dispatch route.
     *
     * Resolves target template to class/method and invokes it with params.
     */

    private function dispatch(array $match, string $lang): void
    {
        $target = (string)$match['target'];
        $params = is_array($match['params'] ?? null) ? $match['params'] : [];
        $keys = is_array($match['keys'] ?? null) ? $match['keys'] : [];

        [$classTemplate, $methodTemplate] = $this->splitTarget($target);
        $consumed = array_unique(array_merge(
            $this->extractPlaceholders($classTemplate),
            $this->extractPlaceholders($methodTemplate)
        ));

        $classResolved = $this->resolveTemplate($classTemplate, $params);
        $method = $this->resolveTemplate($methodTemplate, $params);

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $method)) {
            throw new RuntimeException('Invalid action name: ' . $method);
        }

        if (str_starts_with($method, '_')) {
            throw new RuntimeException('Action cannot start with underscore.');
        }

        $class = $this->normalizeControllerClass($classResolved);
        if (!class_exists($class)) {
            throw new RuntimeException('Controller not found: ' . $class);
        }

        $controller = new $class();
        $intl = $this->locales[$lang] ?? [];

        Registry::set('/ctx/root', $this->rootPath);
        Registry::set('/ctx/app', $this->appPath);
        Registry::set('/ctx/lang', $lang);
        Registry::set('/ctx/intl', $intl);
        Registry::set('/ctx/locales', $this->locales);

        if ($controller instanceof Controller) {
            $controller->setContext($this->appPath, $lang, $intl);
        }

        if (!method_exists($controller, $method)) {
            throw new RuntimeException('Method not found: ' . $class . '::' . $method . '()');
        }

        $reflection = new ReflectionMethod($controller, $method);
        if (!$reflection->isPublic()) {
            throw new RuntimeException('Method is not public: ' . $class . '::' . $method . '()');
        }

        $args = [];
        foreach ($keys as $key) {
            if ($key === 'lang' || in_array($key, $consumed, true)) {
                continue;
            }

            if (array_key_exists($key, $params)) {
                $args[] = $params[$key];
            }
        }

        if (!$reflection->isVariadic()) {
            $args = array_slice($args, 0, $reflection->getNumberOfParameters());
        }

        if (count($args) < $reflection->getNumberOfRequiredParameters()) {
            throw new RuntimeException('Not enough route params for action.');
        }

        $reflection->invokeArgs($controller, $args);
    }



    /*
     * Split dispatch target.
     *
     * Splits a `controller->action` target into its two templates.
     */

    private function splitTarget(string $target): array
    {
        $parts = explode('->', $target, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid route target: ' . $target);
        }

        return [$parts[0], $parts[1]];
    }



    /*
     * Resolve template placeholders.
     *
     * Replaces `@name` placeholders with route parameter values.
     */

    private function resolveTemplate(string $template, array $params): string
    {
        $resolved = preg_replace_callback('/@([a-zA-Z_][a-zA-Z0-9_]*)/', function (array $m) use ($params): string {
            return isset($params[$m[1]]) ? (string)$params[$m[1]] : $m[0];
        }, $template);

        return $resolved ?? $template;
    }



    /*
     * Extract placeholders.
     *
     * Returns placeholder keys declared in a template string.
     */

    private function extractPlaceholders(string $template): array
    {
        preg_match_all('/@([a-zA-Z_][a-zA-Z0-9_]*)/', $template, $m);
        return $m[1] ?? [];
    }



    /*
     * Normalize controller class.
     *
     * Converts route target notation into a fully-qualified class name.
     */

    private function normalizeControllerClass(string $target): string
    {
        $target = str_replace('/', '\\', trim($target));
        $target = ltrim($target, '\\');

        if (!str_contains($target, '\\')) {
            $target = 'controllers\\' . $target;
        }

        return $target;
    }



    /*
     * Match unlocalized URL.
     *
     * Tries route matching without language and with stripped lang-like prefix.
     */

    private function matchWithoutLanguage(string $path): array
    {
        $direct = $this->routerWithoutLang->match($path);
        if ($direct !== null) {
            return [$direct, $path];
        }

        $stripped = $this->stripFirstSegmentIfLangLike($path);
        if ($stripped !== $path) {
            $match = $this->routerWithoutLang->match($stripped);
            if ($match !== null) {
                return [$match, $stripped];
            }
        }

        return [null, $path];
    }



    /*
     * Resolve preferred language.
     *
     * Resolves user language from cookie, headers, then fallback locale.
     */

    private function resolvePreferredLanguage(): string
    {
        $cookieLang = strtolower((string)($_COOKIE['lang'] ?? ''));
        if ($this->isSupportedLocale($cookieLang)) {
            return $cookieLang;
        }

        $header = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach ($this->preferredFromHeader($header) as $candidate) {
            if ($this->isSupportedLocale($candidate)) {
                return $candidate;
            }
        }

        return $this->fallbackLang;
    }



    /*
     * Parse accepted languages.
     *
     * Parses `Accept-Language` and returns candidates by quality score.
     */

    private function preferredFromHeader(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $scores = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $pieces = explode(';', $part);
            $tag = strtolower(trim($pieces[0]));
            $lang = explode('-', $tag)[0];

            if (!preg_match('/^[a-z]{2}$/', $lang)) {
                continue;
            }

            $quality = 1.0;
            if (isset($pieces[1]) && preg_match('/q=([0-9.]+)/', $pieces[1], $m) === 1) {
                $quality = (float)$m[1];
            }

            $scores[$lang] = max($scores[$lang] ?? 0.0, $quality);
        }

        arsort($scores, SORT_NUMERIC);
        return array_keys($scores);
    }



    /*
     * Build localized path.
     *
     * Prefixes a plain path with the requested language code.
     */

    private function buildLocalizedPath(string $path, string $lang): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '/' ? '/' . $lang : '/' . $lang . $path;
    }



    /*
     * Redirect.
     *
     * Sends an HTTP redirect preserving the original query string.
     */

    private function redirect(string $path, string $query = ''): void
    {
        $location = $path;
        if ($query !== '') {
            $location .= '?' . $query;
        }

        header('Location: ' . $location, true, 302);
    }



    /*
     * Persist language.
     *
     * Stores the selected language in a cookie.
     */

    private function persistLanguage(string $lang): void
    {
        setcookie('lang', $lang, [
            'expires' => time() + (86400 * 365),
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }



    /*
     * Check localized match.
     *
     * Returns true when route params include a supported locale.
     */

    private function hasSupportedLang(array $match): bool
    {
        $lang = strtolower((string)($match['params']['lang'] ?? ''));
        return $this->isSupportedLocale($lang);
    }



    /*
     * Check supported locale.
     *
     * Returns true if language is present in locale files.
     */

    private function isSupportedLocale(string $lang): bool
    {
        return $lang !== '' && array_key_exists($lang, $this->locales);
    }



    /*
     * Strip language placeholder.
     *
     * Converts `'/@lang/foo'` to `'/foo'` for redirect detection.
     */

    private function stripLangPlaceholder(string $pattern): ?string
    {
        if (!str_starts_with($pattern, '/@lang')) {
            return null;
        }

        $rest = substr($pattern, strlen('/@lang'));
        if ($rest === false || $rest === '') {
            return '/';
        }

        return '/' . ltrim($rest, '/');
    }



    /*
     * Strip first segment.
     *
     * Removes leading lang-like segment from a path when present.
     */

    private function stripFirstSegmentIfLangLike(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $first = strtolower((string)($segments[0] ?? ''));

        if (!preg_match('/^[a-z]{2}$/', $first)) {
            return $path;
        }

        array_shift($segments);
        $rest = implode('/', $segments);
        return $rest === '' ? '/' : '/' . $rest;
    }



    /*
     * Resolve request path.
     *
     * Returns the canonical request path.
     */

    private function requestPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = '/' . ltrim($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }



    /*
     * Resolve request query.
     *
     * Returns the raw query string, without leading `?`.
     */

    private function requestQuery(): string
    {
        return (string)($_SERVER['QUERY_STRING'] ?? '');
    }



    /*
     * Send 404 response.
     *
     * Sends a plain `404 Not Found` response.
     */

    private function send404(): void
    {
        http_response_code(404);
        echo '404 Not Found';
    }
}
