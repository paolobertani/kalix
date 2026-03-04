<?php
declare(strict_types=1);

namespace Kalix;

use mysqli;

interface ConnectionProvider
{
    public function connection(string $name = 'default'): mysqli;
}
