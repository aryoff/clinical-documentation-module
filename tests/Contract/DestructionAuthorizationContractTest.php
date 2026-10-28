<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\DestructionAuthorizationContract;
use Tests\TestCase;

/** Provider evidence for `clinicaldocumentation.destruction-authorization`; behaviour is proven in Feature/ClinicalRecordDestructionTest. */
class DestructionAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_module_declares_the_capability_with_its_contract_document(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')), true, 512, JSON_THROW_ON_ERROR);
        $provider = collect($manifest['capabilities']['provides'])->firstWhere('id', 'clinicaldocumentation.destruction-authorization');

        $this->assertNotNull($provider);
        $this->assertSame('1.0.0', $provider['version']);
        $this->assertSame(['sync'], $provider['modes']);
        $this->assertSame(DestructionAuthorizationContract::class, $provider['binding']);
        $this->assertFileExists(base_path('Modules/ClinicalDocumentation/'.$provider['contracts']['sync']));
    }

    public function test_an_unknown_authorization_is_never_permitted(): void
    {
        $answer = app(DestructionAuthorizationContract::class)->permits('not-a-uuid', 'p', 'laboratory', 'result_version', 'r');

        $this->assertFalse($answer['permitted']);
    }
}
