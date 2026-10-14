<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\DiagnosisFactRepublicationContract;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Support\DiagnosisAssertionSnapshot;

final class DiagnosisFactRepublication implements DiagnosisFactRepublicationContract
{
    /** @var array<string, array{events: Dispatcher, authorize: callable}> */
    private array $consumers = [];

    public function __construct(private readonly Container $container) {}

    public function registerConsumer(string $consumer, callable $receive, callable $authorize): void
    {
        // First registration owns the route; callers cannot replace its gate or inbox.
        if ($consumer === '' || isset($this->consumers[$consumer])) {
            return;
        }
        $events = new Dispatcher($this->container);
        $events->listen(DiagnosisAssertionFactPublisher::EVENT, $receive);
        $this->consumers[$consumer] = ['events' => $events, 'authorize' => $authorize];
    }

    public function request(string $assertionId, string $consumer): void
    {
        $requester = $this->consumers[$consumer] ?? null;
        if ($requester === null || ($requester['authorize'])() !== true || ! Str::isUuid($assertionId)) {
            return;
        }
        $assertion = DiagnosisAssertion::query()->find($assertionId);
        if ($assertion === null) {
            return;
        }
        // This dispatcher has exactly the requester's listener. Using the global
        // bus would send an unsolicited duplicate to every other fact consumer.
        $requester['events']->dispatch(DiagnosisAssertionFactPublisher::EVENT, [
            DiagnosisAssertionSnapshot::from($assertion) + [
                'supersedes_assertion_id' => $assertion->supersedes_assertion_id,
            ],
        ]);
    }
}
