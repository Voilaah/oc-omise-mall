<?php

use Illuminate\Http\Request;
use Voilaah\OmiseMall\Classes\Payments\OmisePaynowProvider;
use Voilaah\OmiseMall\Classes\Payments\OmiseCheckoutProvider;

Route::get('/omise-checkout', function (Request $request) {

    $omiseCheckout = new OmiseCheckoutProvider;

    $omiseCheckout->changePaymentState($request);

    return exit();
});

Route::get('/omise-paynow', function (Request $request) {

    $omiseCheckout = new OmisePaynowProvider;

    $omiseCheckout->changePaymentState($request);

    return exit();
});
