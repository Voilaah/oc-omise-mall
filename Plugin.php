<?php namespace Voilaah\OmiseMall;

use System\Classes\PluginBase;

use OFFLINE\Mall\Models\Customer;
use OFFLINE\Mall\Classes\Utils\Money;
use OFFLINE\Mall\Classes\Payments\PaymentGateway;
use Voilaah\OmiseMall\Classes\DefaultMoneyRepair;
use Voilaah\OmiseMall\Classes\Payments\OmisePaynowProvider;
use Voilaah\OmiseMall\Classes\Payments\OmiseCheckoutProvider;

class Plugin extends PluginBase
{
    public $require = ['Offline.Mall'];

    public function boot()
    {
        // Extends MALL Customer model
        Customer::extend(function ($model) {
            $model->addFillable(['omise_customer_id']);
        });

        // register our payment gateway provider
        $gateway = $this->app->get(PaymentGateway::class);
        $gateway->registerProvider(new OmiseCheckoutProvider());
        // $gateway->registerProvider(new OmisePaynowProvider());

        // To solve this issue https://github.com/OFFLINE-GmbH/oc-mall-plugin/issues/258
        $this->app->singleton(Money::class, function () {
            return new DefaultMoneyRepair();
        });
    }

    public function registerComponents()
    {
    }

    public function registerSettings()
    {
    }
}
