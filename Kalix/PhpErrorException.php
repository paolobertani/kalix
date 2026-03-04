<?php
declare(strict_types=1);

namespace Kalix;

use ErrorException;

final class PhpErrorException extends ErrorException
{
    private array $stackBacktrace;



    /*
     * Construct PHP error exception.
     *
     * Wraps native PHP errors with a normalized stack backtrace payload.
     */

    public function __construct(
        int $severity,
        string $message,
        string $file,
        int $line,
        array $stackBacktrace
    ) {
        parent::__construct($message, 0, $severity, $file, $line);
        $this->stackBacktrace = $stackBacktrace;
    }



    /*
     * Get stack backtrace.
     *
     * Returns the normalized stack frames captured for this PHP error.
     */

    public function stackBacktrace(): array
    {
        return $this->stackBacktrace;
    }
}
