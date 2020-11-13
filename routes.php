<?php

use Illuminate\Http\Request;
use Voilaah\OmiseMall\Classes\Payments\OmisePaynowProvider;
use Voilaah\OmiseMall\Classes\Payments\OmiseCheckoutProvider;

Route::post('/omise-checkout', function (Request $request) {

    $omiseCheckout = new OmiseCheckoutProvider;

    $omiseCheckout->changePaymentState($request);

    return exit();
});

Route::post('/omise-paynow', function (Request $request) {

    $omiseCheckout = new OmisePaynowProvider;

    $omiseCheckout->changePaymentState($request);

    return exit();
});
