<?php
declare(strict_types=1);

namespace Kalix;

final class Autoload
{
    private static bool $registered = false;
    private static string $kalixPath = '';
    private static string $appPath = '';



    /*
     * Register class autoload.
     *
     * Registers a single autoloader for Kalix and app namespaces.
     */

    public static function register(string $kalixPath, string $appPath): void
    {
        if (self::$registered) {
            return;
        }

        self::$kalixPath = rtrim($kalixPath, '/');
        self::$appPath = rtrim($appPath, '/');

        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;
    }



    /*
     * Load class file.
     *
     * Resolves a class name into a concrete file path.
     */

    public static function loadClass(string $class): void
    {
        $class = ltrim($class, '\\');
        if ($class === '') {
            return;
        }

        $map = [
            'Kalix\\' => self::$kalixPath,
            'controllers\\' => self::$appPath . '/controllers',
            'models\\' => self::$appPath . '/models',
            'mappers\\' => self::$appPath . '/mappers',
            'helpers\\' => self::$appPath . '/helpers',
            'api\\' => self::$appPath . '/api',
        ];

        foreach ($map as $prefix => $basePath) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $relative = str_replace('\\', '/', $relative);

            $file = $basePath . '/' . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
}
