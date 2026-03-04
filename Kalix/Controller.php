<?php
declare(strict_types=1);

namespace Kalix;

use DateTimeInterface;
use ReflectionClass;
use RuntimeException;

abstract class Controller
{
    protected readonly string $lang;
    protected readonly Db $db;
    protected readonly ConnectionProvider $connections;
    protected array $intl = [];
    private string $appPath = '';



    /*
     * Construct controller.
     *
     * Creates a lazy DB handle for the controller lifecycle.
     */

    public function __construct(string $connectionName = 'default', ?ConnectionProvider $connections = null)
    {
        $this->connections = $connections ?? new DbConnectionProvider();
        $this->db = new Db($connectionName);
    }



    /*
     * Set context.
     *
     * Injects framework context into the controller before dispatch.
     */

    public function setContext(string $appPath, string $lang, array $intl): void
    {
        $this->appPath = rtrim($appPath, '/');
        if (!isset($this->lang)) {
            $this->lang = $lang;
        }

        $this->intl = $intl;
        $this->persistLanguageCookie($this->lang);
    }



    /*
     * Render view.
     *
     * Renders `{controller}_{action}.php` and applies Kalix placeholders.
     */

    protected function render(array $params = []): void
    {
        $params['lang'] = $this->lang;

        $action = $this->resolveActionName();
        $view = $this->resolveViewFile($action);
        $html = $this->includeView($view, $params, $this->intl);
        $html = $this->replaceEscapedVariables($html, $params);
        $html = $this->replaceIntlTokens($html, $this->intl);
        echo $html;
    }



    /*
     * Resolve action name.
     *
     * Detects the controller action that called `render`.
     */

    private function resolveActionName(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $action = (string)($trace[2]['function'] ?? 'index');
        return $action;
    }



    /*
     * Resolve view file.
     *
     * Determines the current controller/action view filename.
     */

    private function resolveViewFile(string $action): string
    {
        $controller = strtolower((new ReflectionClass($this))->getShortName());

        $file = $this->appPath . '/views/' . $controller . '_' . $action . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $file);
        }

        return $file;
    }



    /*
     * Include view file.
     *
     * Includes the view in isolated scope and captures rendered HTML.
     */

    private function includeView(string $file, array $params, array $intl): string
    {
        ob_start();

        foreach ($params as $key => $value) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$key) === 1) {
                ${$key} = $value;
            }
        }

        include $file;
        return (string)ob_get_clean();
    }



    /*
     * Replace escaped variables.
     *
     * Replaces `{{name}}` occurrences with escaped values from `$params`.
     */

    private function replaceEscapedVariables(string $html, array $params): string
    {
        $escaped = preg_replace_callback('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', function (array $m) use ($params): string {
            $key = $m[1];
            $value = $params[$key] ?? '';
            return $this->escape($value);
        }, $html);

        return $escaped ?? $html;
    }



    /*
     * Replace intl tokens.
     *
     * Replaces `|token|` occurrences with escaped translations.
     */

    private function replaceIntlTokens(string $html, array $intl): string
    {
        $translated = preg_replace_callback('/\|([a-zA-Z0-9_.-]+)\|/', function (array $m) use ($intl): string {
            $label = $m[1];
            $value = $intl[$label] ?? $label;
            return $this->escape($value);
        }, $html);

        return $translated ?? $html;
    }



    /*
     * Escape value.
     *
     * Converts supported values to string and HTML-escapes them.
     */

    private function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (!is_scalar($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            $value = $encoded === false ? '' : $encoded;
        }

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }



    /*
     * Encode to base64url.
     *
     * Encodes a string as URL-safe base64 without padding.
     */

    public function toBase64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }



    /*
     * Persist language cookie.
     *
     * Stores current language for future routing fallbacks.
     */

    private function persistLanguageCookie(string $lang): void
    {
        setcookie('lang', $lang, [
            'expires' => time() + (86400 * 365),
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
