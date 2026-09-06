<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services\Capabilities;

use Modules\ClinicalDocumentation\Contracts\EmergencyAccessReviewPort;
use Modules\ClinicalDocumentation\Support\CapabilityGateway;

/** The facility's one emergency-access review queue, which the registry owns. */
final class CapabilityEmergencyAccessReview implements EmergencyAccessReviewPort
{
    public const CAPABILITY_ID = 'hospitalcore.emergency-access-review';

    /** What this module calls itself in the queue. */
    public const GRANTING_CONTEXT = 'clinicaldocumentation';

    public function __construct(private readonly CapabilityGateway $gateway) {}

    public function publish(array $fact): array
    {
        return $this->gateway->call(
            self::CAPABILITY_ID,
            'recordEmergencyAccess',
            [$fact + ['granting_context' => self::GRANTING_CONTEXT]],
        );
    }
}
