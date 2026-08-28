<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Support;

use App\Support\CapabilityRegistry;
use Illuminate\Contracts\Container\Container;
use Modules\ClinicalDocumentation\Exceptions\CapabilityUnavailable;

/** Resolves declared capabilities without importing their provider module. */
final class CapabilityGateway
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Container $container,
    ) {}

    /** @param list<mixed> $arguments */
    public function callIfAvailable(string $capabilityId, string $method, array $arguments = []): mixed
    {
        foreach ($this->registry->providerBindings($capabilityId) as $binding) {
            if (!$this->container->bound($binding)) {
                continue;
            }

            $provider = $this->container->make($binding);
            if (!method_exists($provider, $method)) {
                throw CapabilityUnavailable::doesNotSupport($capabilityId, $method);
            }

            return $provider->{$method}(...$arguments);
        }

        return null;
    }
}
