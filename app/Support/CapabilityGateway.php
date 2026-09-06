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

    /**
     * Invoke an operation on a capability whose absence is a supported state.
     *
     * Returns null when no provider is enabled, so the caller's absent path and
     * its "the provider returned nothing" path stay the same path.
     *
     * @param list<mixed> $arguments
     */
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

    /**
     * Invoke an operation on a capability this module cannot work without.
     *
     * Its own method rather than a flag, because the two answers to an absent
     * provider are genuinely different decisions: staging evidence without a
     * registration is a shape this module supports, and granting emergency
     * access that nobody can review is not.
     *
     * @param list<mixed> $arguments
     */
    public function call(string $capabilityId, string $method, array $arguments = []): mixed
    {
        if ($this->provider($capabilityId) === null) {
            throw CapabilityUnavailable::required($capabilityId);
        }

        return $this->callIfAvailable($capabilityId, $method, $arguments);
    }

    private function provider(string $capabilityId): ?object
    {
        foreach ($this->registry->providerBindings($capabilityId) as $binding) {
            if (!$this->container->bound($binding)) {
                continue;
            }

            $provider = $this->container->make($binding);

            if (is_object($provider)) {
                return $provider;
            }
        }

        return null;
    }
}
