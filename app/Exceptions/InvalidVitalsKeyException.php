<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Exceptions;

use InvalidArgumentException;

class InvalidVitalsKeyException extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf("The vitals sign key '%s' is not supported by the system schema.", $key));
    }
}
