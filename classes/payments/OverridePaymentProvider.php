<?php

namespace Voilaah\OmiseMall\Classes\Payments;

use October\Rain\Parse\Twig;
use OFFLINE\Mall\Models\Order;


/**
 * A PaymentProvider handles the integration with external
 * payment providers.
 */
abstract class OverridePaymentProvider extends \OFFLINE\Mall\Classes\Payments\PaymentProvider
{
    /**
    * Overide this method ot include our plugin path folders
    * Renders the payment form partial.
    *
    * @param Cart|Order $cartOrOrder
    *
    * @return string
    */
    public function renderPaymentForm($cartOrOrder): string
    {

        if ('' == parent::renderPaymentForm($cartOrOrder)) {

            // trace_log($this->identifier());
            // trace_log($this->paymentFormPartial());

            $fallback = plugins_path(sprintf(
                'voilaah/omisemall/classes/payments/%s/%s.htm',
                $this->identifier(),
                $this->paymentFormPartial()
            ));

            return file_exists($fallback)
            ? (new Twig)->parse(file_get_contents($fallback), ['cart' => $cartOrOrder])
            : '';
        }

    }
}
