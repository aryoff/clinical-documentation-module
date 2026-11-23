<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Support;

use App\Support\CapabilityRegistry;

/**
 * Registers diagnostic specialties for one test, the way real ones register: each is a module manifest declaring a `diagnostics.specialties`
 * extension, read by a real capability registry over a temporary modules tree. The evidence contract accepts a source only when it is
 * registered, so a test names the registrations it stands on instead of depending on which modules this checkout enables.
 */
trait RegistersDiagnosticSpecialties
{
    /** @param list<string> $kinds service kinds registered at `diagnostics.specialties` (none: Diagnostics alone, a core-only composition) */
    private function registerSpecialties(array $kinds): void
    {
        if (! is_file(base_path('Modules/Diagnostics/module.json'))) {
            $this->markTestSkipped('The registered-specialty evidence needs the Diagnostics manifest that declares the extension point.');
        }

        $root = sys_get_temp_dir().'/cd-specialties-'.bin2hex(random_bytes(6));
        $modules = $root.'/Modules';
        $statuses = [];
        foreach (['Diagnostics', 'HospitalCore', 'Warehouse', 'Cashier', 'ClinicalDocumentation'] as $module) {
            if (! is_file(base_path("Modules/$module/module.json"))) {
                continue;
            }
            mkdir("$modules/$module", 0777, true);
            foreach (['module.json', 'composer.json'] as $file) {
                if (is_file(base_path("Modules/$module/$file"))) {
                    copy(base_path("Modules/$module/$file"), "$modules/$module/$file");
                }
            }
            $statuses[$module] = true;
        }
        $this->copyTree(base_path('Modules/Diagnostics/contracts'), "$modules/Diagnostics/contracts");
        $this->copyTree(base_path('Modules/Diagnostics/tests/Contract'), "$modules/Diagnostics/tests/Contract");

        foreach ($kinds as $kind) {
            $module = ucfirst($kind);
            $path = "$modules/$module";
            mkdir("$path/contracts", 0777, true);
            mkdir("$path/tests/Contract", 0777, true);
            file_put_contents("$path/contracts/adapter.md", 'adapter');
            file_put_contents("$path/tests/Contract/AdapterTest.php", '<?php');
            file_put_contents("$path/composer.json", json_encode(['name' => 'test/'.$kind], JSON_THROW_ON_ERROR));
            file_put_contents("$path/module.json", json_encode([
                'name' => $module, 'alias' => $kind, 'permissions' => [], 'require' => ['Diagnostics'],
                'capabilities' => [
                    'provides' => [[
                        'id' => $kind.'.diagnostic-adapter', 'version' => '1.0.0', 'modes' => ['sync'], 'binding' => 'Fixture\\'.$module.'Adapter',
                        'contracts' => ['sync' => 'contracts/adapter.md'], 'providerContractTest' => 'tests/Contract/AdapterTest.php',
                    ]],
                    'consumes' => [],
                    'extensions' => [[
                        'point' => 'diagnostics.specialties', 'pointVersion' => '^1.0', 'key' => $kind, 'capability' => $kind.'.diagnostic-adapter',
                        'descriptor' => [
                            'service_kind' => $kind,
                            'examination_schemas' => [['id' => $kind.'.examination-request', 'version' => '1.0.0']],
                            'findings_schemas' => [['id' => $kind.'.findings', 'version' => '1.0.0']],
                        ],
                    ]],
                ],
            ], JSON_THROW_ON_ERROR));
            $statuses[$module] = true;
        }

        file_put_contents("$root/modules_statuses.json", json_encode($statuses, JSON_THROW_ON_ERROR));
        $this->app->instance(CapabilityRegistry::class, new CapabilityRegistry($modules, "$root/modules_statuses.json"));
        $this->app->forgetInstance(\Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract::class);
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir("$from/$entry") ? $this->copyTree("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
        }
    }
}
