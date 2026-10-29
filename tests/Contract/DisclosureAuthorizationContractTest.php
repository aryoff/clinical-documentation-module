<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\DisclosureAuthorizationContract;
use Tests\TestCase;

/** Provider evidence for `clinicaldocumentation.disclosure-authorization`; behaviour is proven in Feature/ClinicalDisclosureAuthorizationTest. */
class DisclosureAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_module_declares_the_capability_with_its_contract_document(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')), true, 512, JSON_THROW_ON_ERROR);
        $provider = collect($manifest['capabilities']['provides'])->firstWhere('id', 'clinicaldocumentation.disclosure-authorization');

        $this->assertNotNull($provider);
        $this->assertSame('1.0.0', $provider['version']);
        $this->assertSame(['sync'], $provider['modes']);
        $this->assertSame(DisclosureAuthorizationContract::class, $provider['binding']);
        $this->assertFileExists(base_path('Modules/ClinicalDocumentation/'.$provider['contracts']['sync']));
    }

    public function test_an_unknown_request_or_authorization_is_never_authorized(): void
    {
        $contract = app(DisclosureAuthorizationContract::class);

        $this->assertFalse($contract->authorize('not-a-uuid', 'p', 'laboratory_result', 'r', 'l', true)['authorized']);
        $this->assertFalse($contract->authorize((string) Str::uuid(), 'p', 'laboratory_result', 'r', 'l', true)['authorized']);
        $this->assertFalse($contract->recheck((string) Str::uuid())['authorized']);
    }
}
