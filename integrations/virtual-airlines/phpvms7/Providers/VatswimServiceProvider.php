<?php

namespace Modules\Vatswim\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * VATSWIM Service Provider for phpVMS 7
 *
 * Registers event listeners and services for VATSWIM integration.
 */
class VatswimServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Vatswim';
    protected string $moduleNameLower = 'vatswim';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerEventListeners();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Register VATSWIM client as singleton
        $this->app->singleton('vatswim', function ($app) {
            return new \Modules\Vatswim\Services\VatswimClient(
                config('vatswim.api_key'),
                config('vatswim.api_base_url')
            );
        });
    }

    /**
     * Register event listeners
     */
    protected function registerEventListeners(): void
    {
        // Only register if VATSWIM is enabled
        if (!config('vatswim.enabled', false)) {
            return;
        }

        // PIREP events
        Event::listen(
            \App\Events\PirepFiled::class,
            \Modules\Vatswim\Listeners\PirepFiledListener::class
        );

        Event::listen(
            \App\Events\PirepAccepted::class,
            \Modules\Vatswim\Listeners\PirepAcceptedListener::class
        );

        Event::listen(
            \App\Events\PirepRejected::class,
            \Modules\Vatswim\Listeners\PirepRejectedListener::class
        );

        // Flight events (if using ACARS)
        Event::listen(
            \App\Events\PirepPrefiled::class,
            \Modules\Vatswim\Listeners\PirepPrefiledListener::class
        );
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'),
            $this->moduleNameLower
        );
    }

    /**
     * Register views.
     */
    protected function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    /**
     * Register translations.
     */
    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
        }
    }

    /**
     * Get publishable view paths.
     */
    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }
        return $paths;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return ['vatswim'];
    }
}
