<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Modules\ClinicalDocumentation\Contracts\DiagnosisAssertionFactPublisher as DiagnosisAssertionFactPublisherContract;

class DiagnosisAssertionFactPublisher implements DiagnosisAssertionFactPublisherContract
{
    /** The public event name, which is the capability ID. */
    public const EVENT = 'clinicaldocumentation.diagnosis-assertion-fact-published';

    public function publish(array $fact): void
    {
        // A named scalar event rather than a ClinicalDocumentation class, so a
        // consumer registers an inbox listener without importing this module
        // and only when a compatible provider is resolved.
        event(self::EVENT, [$fact]);
    }
}
