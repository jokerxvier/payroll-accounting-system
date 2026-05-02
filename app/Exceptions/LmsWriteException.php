<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class LmsWriteException extends RuntimeException
{
    public function __construct(string $modelClass, string $action)
    {
        parent::__construct(
            sprintf('Cannot %s %s: LMS tables are read-only.', $action, $modelClass)
        );
    }
}
