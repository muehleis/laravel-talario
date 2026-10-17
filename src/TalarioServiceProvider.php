<?php

namespace Talario\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class TalarioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/talario.php', 'talario');

        // Add our mailer without replacing any application-defined configuration.
        if ($this->app['config']->get('mail.mailers.talario') === null) {
            $this->app['config']->set('mail.mailers.talario', ['transport' => 'talario']);
        }

        $this->app->afterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('talario', function (array $config): TalarioTransport {
                $options = array_replace($this->app['config']->get('talario', []), $config);

                return new TalarioTransport(
                    $this->app->make(Factory::class),
                    $options['api_key'] ?? '',
                    $options['endpoint'] ?? '',
                    (float) ($options['timeout'] ?? 60),
                    (float) ($options['connect_timeout'] ?? 10),
                );
            });
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/talario.php' => config_path('talario.php')], 'talario-config');
    }
}
