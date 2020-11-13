<?php namespace Voilaah\OmiseMall\Classes;

use OFFLINE\Mall\Classes\Utils\DefaultMoney;

/***
 * Helper class to solve this problem https://github.com/OFFLINE-GmbH/oc-mall-plugin/issues/258
 * paypal error after redirect to site
 * After payment via PayPal upon returning to the site, we get an error Call to a member function getCurrent() on null
 */
class DefaultMoneyRepair extends DefaultMoney
{
    protected function render($contents, array $vars)
    {
        return number_format($vars['price'],$vars['currency']->decimals,  ',', ' ').' '.$vars['currency']->symbol;
    }
}
