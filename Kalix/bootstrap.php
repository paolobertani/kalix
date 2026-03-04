<?php
declare(strict_types=1);

require_once __DIR__ . '/Registry.php';
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ConnectionProvider.php';
require_once __DIR__ . '/DbConfig.php';
require_once __DIR__ . '/DbConnectionProvider.php';
require_once __DIR__ . '/Mapper.php';
require_once __DIR__ . '/BadRequestException.php';
require_once __DIR__ . '/PhpErrorException.php';
require_once __DIR__ . '/Kalix.php';

return new \Kalix\Kalix([
    'root' => dirname(__DIR__),
    'app' => dirname(__DIR__) . '/app',
]);
