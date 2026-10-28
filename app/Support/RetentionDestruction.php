<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Support;

/**
 * The one narrow exception to this module's immutability guards: clearing a copy of findings under an approved
 * retention destruction. Open only while one step runs; it closes even when that step throws.
 */
final class RetentionDestruction
{
    private static bool $open = false;

    public static function isOpen(): bool
    {
        return self::$open;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    public static function run(callable $step): mixed
    {
        self::$open = true;
        try {
            return $step();
        } finally {
            self::$open = false;
        }
    }
}
