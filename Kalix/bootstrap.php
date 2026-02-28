<?php
declare(strict_types=1);

require_once __DIR__ . '/Registry.php';
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Mapper.php';
require_once __DIR__ . '/Kalix.php';

return new \Kalix\Kalix([
    'root' => dirname(__DIR__),
    'app' => dirname(__DIR__) . '/app',
]);
