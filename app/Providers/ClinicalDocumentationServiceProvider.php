<?php

namespace Modules\ClinicalDocumentation\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Contracts\DiagnosisAssertionFactPublisher;
use Modules\ClinicalDocumentation\Contracts\DischargeDocumentationContract;
use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Modules\ClinicalDocumentation\Listeners\ReassignReconciledPatient;
use Modules\ClinicalDocumentation\Services\ActiveClinicalRecordService;
use Modules\ClinicalDocumentation\Services\DischargeDocumentationService;
use Modules\ClinicalDocumentation\Services\Capabilities\CapabilityHospitalRegistration;

class ClinicalDocumentationServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'ClinicalDocumentation';

    protected string $moduleNameLower = 'clinicaldocumentation';

    /**
     * The `hospitalcore.patient-identity-reconciled` fact this module subscribes to.
     *
     * Named by value rather than by importing the publisher's event class,
     * which would be a compile-time dependency on a collaborator this module
     * does not require.
     */
    private const PATIENT_RECONCILED_FACT = 'hospitalcore.patient-identity-reconciled';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));

        // A patient registry is optional. The fact is a named string on a
        // public async contract, so this module ships no registry import and
        // the listener is simply never called in a composition that publishes
        // nothing under this name.
        Event::listen(self::PATIENT_RECONCILED_FACT, [ReassignReconciledPatient::class, 'handle']);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
        $this->app->singleton(ActiveClinicalRecordContract::class, ActiveClinicalRecordService::class);
        // The discharge-documentation boundary is its own capability rather
        // than more operations on the active-clinical-record one, because its
        // consumer is different: a ward asks whether an episode's paperwork is
        // finished and must never be able to read what the paperwork says.
        $this->app->singleton(DischargeDocumentationContract::class, DischargeDocumentationService::class);
        // Integrations receive the diagnosis as a scalar snapshot rather than
        // by reading this module's storage, so a queued external submission
        // keeps the meaning it captured even after a later supersession.
        $this->app->singleton(DiagnosisAssertionFactPublisher::class, \Modules\ClinicalDocumentation\Services\DiagnosisAssertionFactPublisher::class);
        $this->app->scoped(HospitalRegistrationPort::class, CapabilityHospitalRegistration::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands(
            array_map(function ($filePath) {
                $className = "\Modules\\" . $this->moduleName . "\Console\\" . substr(basename($filePath), 0, -4);
                return app($className);
            }, glob(module_path($this->moduleName, "app" . DIRECTORY_SEPARATOR . "Console") . DIRECTORY_SEPARATOR . "*.{php}", GLOB_BRACE))
        );
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'lang'), $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(module_path($this->moduleName, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([module_path($this->moduleName, 'config/config.php') => config_path($this->moduleNameLower.'.php')], 'config');
        $this->mergeConfigFrom(module_path($this->moduleName, 'config/config.php'), $this->moduleNameLower);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->moduleNameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);

        $componentNamespace = str_replace('/', '\\', config('modules.namespace').'\\'.$this->moduleName.'\\'.ltrim(config('modules.paths.generator.component-class.path'), config('modules.paths.app_folder', '')));
        Blade::componentNamespace($componentNamespace, $this->moduleNameLower);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->moduleNameLower)) {
                $paths[] = $path.'/modules/'.$this->moduleNameLower;
            }
        }

        return $paths;
    }
}
