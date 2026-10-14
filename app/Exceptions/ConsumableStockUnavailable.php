<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Exceptions;

use RuntimeException;

/** No custodian is enabled, so a consumable use is unavailable rather than kept in a local balance. */
final class ConsumableStockUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            "Medical consumable use is unavailable because no stock custodian is enabled.",
        );
    }
}
