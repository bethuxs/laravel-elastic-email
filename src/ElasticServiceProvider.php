<?php
namespace Chocoholics\LaravelElasticEmail;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use GuzzleHttp\Client as GuzzleClient;

class ElasticServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->singleton('elastic_email', function ($app) {
            $config = $this->app['config']->get('services.elastic_email', []);
            $client = new GuzzleClient(Arr::get($config, 'guzzle', []));
            $transport = new ElasticTransport($client, $config);
            return $transport;
        });
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->app->afterResolving(MailManager::class, function (MailManager $mail_manager) {
            $mail_manager->extend('elastic_email', function ($config) {
                return app('elastic_email');
            });
        });
    }
}
