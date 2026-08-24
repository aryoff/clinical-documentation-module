<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The sidebar payload advertises the routes a user may call, and it reads
 * exactly two signals: a route name that is character-for-character a
 * permission, and what the action's FormRequest::authorize() returns. Authoring,
 * signing and amending were gated by `permission:` middleware alone — enforced,
 * but invisible here — so the payload carried them for every authenticated user
 * and the controls they back rendered for a clinician the Gate refuses.
 *
 * Mirrors Modules/WorkforceManagement/tests/Feature/ZiggyRoutePublishingTest.php,
 * as Modules/AGENTS.md asks of a module that gates a route.
 */
class ZiggyRoutePublishingTest extends TestCase
{
    use RefreshDatabase;

    /** Every route this module gates on a clinical ability. */
    private const CLINICAL_ROUTES = [
        'clinicaldocumentation.documents.author' => [
            'clinicaldocumentation.create',
            'clinicaldocumentation.store',
            'clinicaldocumentation.edit',
            'clinicaldocumentation.update',
        ],
        'clinicaldocumentation.documents.sign' => ['clinicaldocumentation.submit'],
        'clinicaldocumentation.documents.amend' => ['clinicaldocumentation.amend'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_no_clinical_route_is_advertised_to_a_user_without_its_permission(): void
    {
        $outsider = User::factory()->create();

        $published = $this->publishedRoutes($outsider);

        foreach (self::CLINICAL_ROUTES as $ability => $routes) {
            foreach ($routes as $route) {
                $this->assertNotContains($route, $published, "[{$route}] is advertised to a user who cannot {$ability}.");
            }
        }
    }

    /**
     * Holding the permission is not enough once HospitalCore's Gate is in play,
     * and that is the point: an uncredentialed clinician is refused, so the
     * control must not render rather than 403 on press.
     */
    public function test_a_clinical_route_stays_hidden_from_a_permission_holder_the_gate_refuses(): void
    {
        $uncredentialed = User::factory()->create();
        $uncredentialed->givePermissionTo('clinicaldocumentation.documents.sign');

        $this->assertNotContains('clinicaldocumentation.submit', $this->publishedRoutes($uncredentialed));
    }

    public function test_a_credentialed_clinician_is_still_told_about_every_route_they_may_call(): void
    {
        $clinician = User::factory()->create();

        foreach (array_keys(self::CLINICAL_ROUTES) as $ability) {
            $clinician->givePermissionTo($ability);
            $this->credentialActor((string) $clinician->id, $ability);
        }

        // Without HospitalCore composed there is no clinical-authority regime,
        // so there is nothing to credential and nothing this test can prove.
        if (! $this->clinicalAuthorityRegimeIsComposed()) {
            $this->markTestSkipped('No provider binds the clinical-authority credentialing capability.');
        }

        $published = $this->publishedRoutes($clinician);

        foreach (self::CLINICAL_ROUTES as $ability => $routes) {
            foreach ($routes as $route) {
                // The pages resolve these names in the browser; dropping one
                // from the payload makes route() throw there rather than deny.
                $this->assertContains($route, $published, "[{$route}] is withheld from a clinician credentialed for {$ability}.");
            }
        }
    }

    /** @return list<string> */
    private function publishedRoutes(User $user): array
    {
        return array_keys(
            $this->actingAs($user)->getJson(route('populateSidebar'))->assertOk()->json('ziggy.routes'),
        );
    }

    /**
     * Credentialing is asked of HospitalCore by capability ID rather than by
     * importing its contract, so this file still compiles — and this suite still
     * runs — in a fork composed without it.
     */
    private function credentialActor(string $userId, string $ability): void
    {
        $registry = $this->app->make(CapabilityRegistry::class);

        foreach ($registry->providerBindings('hospitalcore.clinical-authority-credentialing') as $binding) {
            if ($this->app->bound($binding)) {
                $this->app->make($binding)->credentialActor(['user_id' => $userId, 'ability' => $ability]);

                return;
            }
        }
    }

    private function clinicalAuthorityRegimeIsComposed(): bool
    {
        foreach ($this->app->make(CapabilityRegistry::class)->providerBindings('hospitalcore.clinical-authority-credentialing') as $binding) {
            if ($this->app->bound($binding)) {
                return true;
            }
        }

        return false;
    }
}
