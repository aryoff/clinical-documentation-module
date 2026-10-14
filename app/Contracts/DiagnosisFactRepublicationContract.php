<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/** A separate capability: the command names one assertion, never a patient or journey. */
interface DiagnosisFactRepublicationContract
{
    /**
     * Trusted consumer bootstrap registers its existing fact listener and a live authorization check.
     * Registration is process-local routing, not a read surface or a durable delivery ledger.
     *
     * @param  callable(array<string, mixed>): void  $receive
     * @param  callable(): bool  $authorize
     */
    public function registerConsumer(string $consumer, callable $receive, callable $authorize): void;

    /** The consumer name is routing context; the request itself is the assertion id alone. */
    public function request(string $assertionId, string $consumer): void;
}
