<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Support;

use App\Models\User;
use App\Support\CapabilityRegistry;

/**
 * Puts a test actor into the credentialed state HospitalCore's Gate requires.
 *
 * `Gate::before` refuses a clinical permission whose holder has no Clinical
 * Authority, so a success-path test that granted the permission alone stopped
 * proving anything about the route it called — it was refused before reaching
 * it. Authoring, signing and amending are all classified clinical.
 *
 * Asked of HospitalCore by capability ID rather than by importing its contract,
 * and a no-op wherever nothing binds it: a composition without the
 * clinical-authority regime has nothing to satisfy, so this module's suite runs
 * unchanged in a fork that omits HospitalCore.
 *
 * Local to this module on purpose. There is no shared trait to reach for: root
 * is a hospital-agnostic kernel whose `tests/Support/` ships to the non-hospital
 * forks, and hosting one in HospitalCore would make a consumer's `use`
 * statement couple exactly what the by-ID lookup exists to avoid.
 */
trait CredentialsClinicalActors
{
    private const CREDENTIALING_CAPABILITY = 'hospitalcore.clinical-authority-credentialing';

    /**
     * Grants a clinical ability and records the authority behind it.
     *
     * Refusal tests deliberately do not use this. One that means "no
     * permission" must keep meaning that, not start passing because nobody
     * credentialed the actor.
     */
    private function grantClinicalAbility(User $user, string $ability): void
    {
        $user->givePermissionTo($ability);
        $this->credentialActor((string) $user->id, $ability);
    }

    private function credentialActor(string $userId, string $ability): void
    {
        foreach ($this->credentialingBindings() as $binding) {
            $this->app->make($binding)->credentialActor(['user_id' => $userId, 'ability' => $ability]);

            return;
        }
    }

    private function clinicalAuthorityRegimeIsComposed(): bool
    {
        return $this->credentialingBindings() !== [];
    }

    /** @return list<string> */
    private function credentialingBindings(): array
    {
        $bound = [];

        foreach ($this->app->make(CapabilityRegistry::class)->providerBindings(self::CREDENTIALING_CAPABILITY) as $binding) {
            if ($this->app->bound($binding)) {
                $bound[] = $binding;
            }
        }

        return $bound;
    }
}
