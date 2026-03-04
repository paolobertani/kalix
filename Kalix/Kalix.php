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
    private array $locales = [];
    private string $fallbackLang = 'en';
    private bool $errorHandlersRegistered = false;
    private bool $handlingErrorController = false;



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
        $this->registerErrorHandlers();

        $this->router = new Router();

        $this->loadDatabaseConfig();
        $this->loadLocales();
        $this->loadRoutes();
    }



    /*
     * Register route.
     *
     * Registers one route pattern and its dispatch target.
     */

    public function route(string $pattern, string $target): self
    {
        $this->router->add($pattern, $target);

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
            $resolved = $this->router->resolve($path);

            if (($resolved['status'] ?? '') === 'matched') {
                $match = is_array($resolved['match'] ?? null) ? $resolved['match'] : [];
                $hasLang = (bool)($match['has_lang'] ?? false);

                if ($hasLang) {
                    $lang = strtolower((string)($match['params']['lang'] ?? ''));
                    if (!$this->isSupportedLocale($lang)) {
                        $this->send404();
                        return;
                    }

                    $this->dispatch($match, $lang);
                    return;
                }

                $lang = $this->resolvePreferredLanguage();
                $this->persistLanguage($lang);
                $this->dispatch($match, $lang);
                return;
            }

            $missingLangMatch = $this->router->matchWithoutLanguage($path);
            if ($missingLangMatch !== null) {
                $lang = $this->resolvePreferredLanguage();
                $this->persistLanguage($lang);
                $localizedPath = $this->router->buildPathWithLanguage($missingLangMatch, $lang);
                $this->redirect($localizedPath, $query, 301);
                return;
            }

            if (($resolved['status'] ?? '') === 'malformed') {
                $this->send400();
                return;
            }

            $this->send404();
        } catch (BadRequestException $e) {
            $this->send400($e->getMessage(), $this->formatThrowableStackBacktrace($e));
        } catch (Throwable $e) {
            $this->handleThrowable($e);
        }
    }



    /*
     * Register error handlers.
     *
     * Installs handlers for PHP runtime errors and fatal shutdown errors.
     */

    private function registerErrorHandlers(): void
    {
        if ($this->errorHandlersRegistered) {
            return;
        }

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $stack = $this->formatPhpErrorStackBacktrace($trace, $file, $line);

            throw new PhpErrorException($severity, $message, $file, $line, $stack);
        });

        set_exception_handler(function (Throwable $e): void {
            $this->handleThrowable($e);
        });

        register_shutdown_function(function (): void {
            $last = error_get_last();
            if (!is_array($last)) {
                return;
            }

            $type = (int)($last['type'] ?? 0);
            if (!$this->isFatalPhpErrorType($type)) {
                return;
            }

            $message = (string)($last['message'] ?? 'Fatal PHP error');
            $file = (string)($last['file'] ?? '');
            $line = (int)($last['line'] ?? 0);
            $stack = [[
                'file_path' => $file,
                'function_name' => '',
                'line_number' => $line,
            ]];

            $this->send500($message, $stack);
        });

        $this->errorHandlersRegistered = true;
    }



    /*
     * Handle throwable.
     *
     * Converts exceptions into HTTP errors and optional custom error pages.
     */

    private function handleThrowable(Throwable $e): void
    {
        if ($e instanceof PhpErrorException) {
            $message = $this->formatPhpErrorMessage($e);
            $this->send500($message, $e->stackBacktrace());
            return;
        }

        $code = $this->normalizeHttpErrorCode((int)$e->getCode(), 500);
        $message = $e->getMessage();
        $stack = $this->formatThrowableStackBacktrace($e);

        if ($code === 400) {
            $this->send400($message, $stack);
            return;
        }

        if ($code === 404) {
            $this->send404($message, $stack);
            return;
        }

        $this->send500($message, $stack);
    }



    /*
     * Dispatch error controller.
     *
     * Calls `controllers\error->error(...)` when available.
     */

    private function dispatchErrorController(int $code, ?string $error = null, ?array $stackBacktrace = null): bool
    {
        if ($this->handlingErrorController) {
            return false;
        }

        $class = 'controllers\\error';
        if (!class_exists($class)) {
            return false;
        }

        $this->handlingErrorController = true;
        try {
            $controller = new $class();
            if (!$controller instanceof Controller) {
                return false;
            }

            if (!method_exists($controller, 'error')) {
                return false;
            }

            $reflection = new ReflectionMethod($controller, 'error');
            if (!$reflection->isPublic()) {
                if (!headers_sent()) {
                    http_response_code(400);
                }

                echo $this->defaultErrorText(400);
                return true;
            }

            $lang = $this->resolveLanguageForErrorContext();
            $intl = $this->locales[$lang] ?? [];
            $this->setRequestContext($lang, $intl);
            $controller->setContext($this->appPath, $lang, $intl);

            $reflection->invoke($controller, $code, $error, $stackBacktrace);
            return true;
        } catch (Throwable) {
            if (!headers_sent()) {
                http_response_code(500);
            }

            echo $this->defaultErrorText(500);
            return true;
        } finally {
            $this->handlingErrorController = false;
        }
    }



    /*
     * Set request context.
     *
     * Stores request-level context values in the shared registry.
     */

    private function setRequestContext(string $lang, array $intl): void
    {
        Registry::set('/ctx/root', $this->rootPath);
        Registry::set('/ctx/app', $this->appPath);
        Registry::set('/ctx/lang', $lang);
        Registry::set('/ctx/intl', $intl);
        Registry::set('/ctx/locales', $this->locales);
    }



    /*
     * Resolve error language.
     *
     * Prefers URL locale and falls back to preferred language detection.
     */

    private function resolveLanguageForErrorContext(): string
    {
        $path = $this->requestPath();
        $segments = explode('/', trim($path, '/'));
        $first = strtolower((string)($segments[0] ?? ''));

        if (preg_match('/^[a-z]{2}$/', $first) === 1 && $this->isSupportedLocale($first)) {
            return $first;
        }

        return $this->resolvePreferredLanguage();
    }



    /*
     * Normalize throwable stack.
     *
     * Returns trace frames where the last item is the error location.
     */

    private function formatThrowableStackBacktrace(Throwable $e): array
    {
        $items = [];
        $trace = $e->getTrace();
        $ordered = array_reverse($trace);

        foreach ($ordered as $frame) {
            $items[] = $this->stackItemFromFrame($frame);
        }

        $items[] = [
            'file_path' => $e->getFile(),
            'function_name' => (string)($trace[0]['function'] ?? ''),
            'line_number' => $e->getLine(),
        ];

        return $this->normalizeStackBacktrace($items);
    }



    /*
     * Normalize PHP error stack.
     *
     * Returns trace frames where the last item is the PHP error location.
     */

    private function formatPhpErrorStackBacktrace(array $trace, string $file, int $line): array
    {
        $items = [];
        $ordered = array_reverse($trace);

        foreach ($ordered as $frame) {
            $items[] = $this->stackItemFromFrame($frame);
        }

        $items[] = [
            'file_path' => $file,
            'function_name' => '',
            'line_number' => $line,
        ];

        return $this->normalizeStackBacktrace($items);
    }



    /*
     * Stack item from frame.
     *
     * Maps one debug backtrace frame to the specification format.
     */

    private function stackItemFromFrame(array $frame): array
    {
        return [
            'file_path' => (string)($frame['file'] ?? ''),
            'function_name' => (string)($frame['function'] ?? ''),
            'line_number' => (int)($frame['line'] ?? 0),
        ];
    }



    /*
     * Normalize stack backtrace.
     *
     * Cleans stack rows and guarantees non-empty output shape.
     */

    private function normalizeStackBacktrace(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $normalized[] = [
                'file_path' => (string)($item['file_path'] ?? ''),
                'function_name' => (string)($item['function_name'] ?? ''),
                'line_number' => (int)($item['line_number'] ?? 0),
            ];
        }

        if ($normalized === []) {
            return [[
                'file_path' => '',
                'function_name' => '',
                'line_number' => 0,
            ]];
        }

        return $normalized;
    }



    /*
     * Format PHP error message.
     *
     * Prefixes message with severity label.
     */

    private function formatPhpErrorMessage(PhpErrorException $e): string
    {
        $label = $this->phpErrorLabel($e->getSeverity());
        return $label . ': ' . $e->getMessage();
    }



    /*
     * Resolve PHP error label.
     *
     * Returns a human-readable label for a PHP error severity.
     */

    private function phpErrorLabel(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }



    /*
     * Check fatal PHP error.
     *
     * Returns true when shutdown error type should be rendered as 500.
     */

    private function isFatalPhpErrorType(int $type): bool
    {
        return in_array($type, [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ], true);
    }



    /*
     * Normalize HTTP error code.
     *
     * Ensures response status is a valid HTTP error status.
     */

    private function normalizeHttpErrorCode(int $code, int $fallback = 500): int
    {
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        return $fallback;
    }



    /*
     * Default error text.
     *
     * Returns plain fallback text for framework errors.
     */

    private function defaultErrorText(int $code): string
    {
        return match ($code) {
            400 => '400 Bad Request',
            404 => '404 Not Found',
            default => '500 Internal Server Error',
        };
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

        $this->fallbackLang = $this->determineFallbackLanguage();
    }



    /*
     * Determine fallback language.
     *
     * Resolves fallback locale using single/default/en-first rules.
     */

    private function determineFallbackLanguage(): string
    {
        if ($this->locales === []) {
            return 'en';
        }

        $keys = array_keys($this->locales);
        if (count($keys) === 1) {
            return (string)$keys[0];
        }

        foreach ($this->locales as $lang => $tokens) {
            if ((bool)($tokens['default'] ?? false) === true) {
                return (string)$lang;
            }
        }

        if (in_array('en', $keys, true)) {
            return 'en';
        }

        return (string)$keys[0];
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
            throw new BadRequestException('Invalid action name: ' . $method);
        }

        if (str_starts_with($method, '_')) {
            throw new BadRequestException('Action cannot start with underscore.');
        }

        $class = $this->normalizeControllerClass($classResolved);
        if (!class_exists($class)) {
            throw new BadRequestException('Controller not found: ' . $class);
        }

        $controller = new $class();
        if (!$controller instanceof Controller) {
            throw new BadRequestException('Controller must extend Kalix\\Controller: ' . $class);
        }

        $intl = $this->locales[$lang] ?? [];
        $this->setRequestContext($lang, $intl);

        $controller->setContext($this->appPath, $lang, $intl);

        if (!method_exists($controller, $method)) {
            throw new BadRequestException('Method not found: ' . $class . '::' . $method . '()');
        }

        $reflection = new ReflectionMethod($controller, $method);
        if (!$reflection->isPublic()) {
            throw new BadRequestException('Method is not public: ' . $class . '::' . $method . '()');
        }

        $declaringClass = $reflection->getDeclaringClass()->getName();
        if ($declaringClass !== $class) {
            throw new BadRequestException('Action must be declared in controller class: ' . $class . '::' . $method . '()');
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
            throw new BadRequestException('Not enough route params for action.');
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
     * Redirect.
     *
     * Sends an HTTP redirect preserving the original query string.
     */

    private function redirect(string $path, string $query = '', int $status = 302): void
    {
        $location = $path;
        if ($query !== '') {
            $location .= '?' . $query;
        }

        header('Location: ' . $location, true, $status);
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
     * Check supported locale.
     *
     * Returns true if language is present in locale files.
     */

    private function isSupportedLocale(string $lang): bool
    {
        return $lang !== '' && array_key_exists($lang, $this->locales);
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
     * Renders custom or fallback `404` output.
     */

    private function send404(?string $error = null, ?array $stackBacktrace = null): void
    {
        if (!headers_sent()) {
            http_response_code(404);
        }

        if ($this->dispatchErrorController(404, $error, $stackBacktrace)) {
            return;
        }

        echo $this->defaultErrorText(404);
    }



    /*
     * Send 400 response.
     *
     * Renders custom or fallback `400` output.
     */

    private function send400(?string $error = null, ?array $stackBacktrace = null): void
    {
        if (!headers_sent()) {
            http_response_code(400);
        }

        if ($this->dispatchErrorController(400, $error, $stackBacktrace)) {
            return;
        }

        echo $this->defaultErrorText(400);
    }



    /*
     * Send 500 response.
     *
     * Renders custom or fallback `500` output.
     */

    private function send500(?string $error = null, ?array $stackBacktrace = null): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($this->dispatchErrorController(500, $error, $stackBacktrace)) {
            return;
        }

        echo $this->defaultErrorText(500);
    }
}
