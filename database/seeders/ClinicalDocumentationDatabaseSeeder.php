<?php

namespace Modules\ClinicalDocumentation\Database\Seeders;

use App\Support\ModuleService;
use Illuminate\Database\Seeder;
use Nwidart\Modules\Facades\Module;


class ClinicalDocumentationDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $moduleService = new ModuleService();
        $module = Module::find('ClinicalDocumentation');
        $moduleService->createPermissions($module);
        $moduleService->createDictionaryEntry($module);
        // $this->call([]);
    }
}
