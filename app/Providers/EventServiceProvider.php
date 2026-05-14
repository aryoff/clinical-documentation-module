<?php

namespace Modules\ClinicalDocumentation\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * Override the parent method to enable discovery for module EventServiceProviders.
     * The parent only allows discovery for the exact base class, not subclasses.
     *
     * @return bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return static::$shouldDiscoverEvents === true;
    }

    /**
     * Get the listener directories that should be used to discover events.
     *
     * @return array<int, string>
     */
    protected function discoverEventsWithin(): array
    {
        return [__DIR__ . "/../Listeners"];
    }

    /**
     * Discover the events and listeners for the application.
     *
     * Override to provide custom class name resolution for modules.
     *
     * @return array
     */
    public function discoverEvents()
    {
        // Provide a custom callback for class name resolution
        \Illuminate\Foundation\Events\DiscoverEvents::guessClassNamesUsing(
            function ($file, $basePath) {
                // For module listeners, extract class from namespace
                $filePath = $file->getRealPath();

                // Check if this is a module file
                if (str_contains($filePath, '/Modules/')) {
                    // Extract the module-relative path
                    preg_match('/Modules\/([^\/]+)\/app\/(.+)\.php$/', $filePath, $matches);

                    if (count($matches) === 3) {
                        $moduleName = $matches[1];
                        $classPath = str_replace('/', '\\', $matches[2]);
                        return "Modules\\{$moduleName}\\{$classPath}";
                    }
                }

                // Fall back to default Laravel logic
                return null;
            }
        );

        return parent::discoverEvents();
    }

    /**
     * Configure the proper event listeners for email verification.
     *
     * @return void
     */
    protected function configureEmailVerification(): void
    {

    }
}
