<?php namespace Voilaah\OmiseMall;

use System\Classes\PluginBase;

use OFFLINE\Mall\Classes\Payments\PaymentGateway;
use Voilaah\OmiseMall\Classes\OmisePaynowProvider;
use Voilaah\OmiseMall\Classes\OmiseCheckoutProvider;

class Plugin extends PluginBase
{
    public $require = ['Offline.Mall'];

    public function boot()
    {
        $gateway = $this->app->get(PaymentGateway::class);
        $gateway->registerProvider(new OmiseCheckoutProvider());
        $gateway->registerProvider(new OmisePaynowProvider());
    }

    public function registerComponents()
    {
    }

    public function registerSettings()
    {
    }
}
