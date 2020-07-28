<?php

namespace Chocoholics\LaravelElasticEmail;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use SendinBlue\Client\Api\SMTPApi;
use SendinBlue\Client\Configuration;
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
        $this->app[MailManager::class]->extend('elastic_email', function ($app) {
            $config = $app['config']->get('services.elastic_email', []);
            $client = $this->app->make(GuzzleClient::class, $config);
            return new ElasticTransport($client, $config);
        });
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        /*$this->app->singleton(SMTPApi::class, function ($app) {
            $config = Configuration::getDefaultConfiguration()->setApiKey($app['config']['services.sendinblue.key_identifier'], $app['config']['services.sendinblue.key']);

            return new SMTPApi(
                new GuzzleClient,
                $config
            );
        });*/
    }
}
