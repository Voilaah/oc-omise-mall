<?php

namespace Voilaah\OmiseMall\Classes\Payments;

use StdClass;

Class OmiseHelper
{


    /**
     * Get the response data.
     * @deprecated
     * @return mixed
     */
    public static function getData($response)
    {
        return $response; //->data;
    }


    /**
     *
     */
    public static function buildResponse($response)
    {
        return new PaymentResponse( $response->offsetGet('failure_message') );
    }

    public static function isSuccessful($response)
    {
        return $response->offsetExists('paid') & $response->offsetGet('paid') === true &&
            $response->offsetExists('status') & $response->offsetGet('status') === 'successful';
    }

    public static function isPending($response)
    {
        return $response->offsetExists('status') & $response->offsetGet('status') === 'pending';
    }

    public static function isFailed($response)
    {
        return $response->offsetExists('status') & $response->offsetGet('status') === 'failed';
    }


    /**
     * Should be implemented in the Omnipay Omise
     * */
    public static function getCustomerReference($response)
    {
        // $data = (array)static::getData($response);
        if ($response->offsetExists('object') && $response->offsetGet('object') === 'customer') {
            return $response->offsetGet('id');
        }

        return null;
    }

    /**
     * Should be implemented in the Omnipay Omise
     * */
    public static function getCardReference($response, $last_digits = null)
    {
        // $data = (array)static::getData($response);
        if ($response->offsetExists('object') && $response->offsetGet('object') === 'card') {
            return $response->offsetGet('id');
        }

        if ($response->offsetExists('object') && $response->offsetGet('object') === 'customer') {
            $cards = $response->offsetGet('cards');
            $total = $cards['total'];
            if (1 == $total) {
                return $cards['data'][0]['id'];
            } else {
                if (!$last_digits) {
                    foreach ($cards['data'] as $key => $card) {
                        if ($last_digits == $card['last_digits']) {
                            return $card->offsetGet('id');
                            break;
                        }
                    }
                }
            }
            return null;
        }

        return null;
    }



    /**
     * Override the current Omnipay Omise code
     * */
    public static function getTransactionReference($response)
    {

        if ($response->offsetExists('object')
            && 'charge' === $response->offsetGet('object')
            && $response->offsetExists('id')
        ){
            return $response->offsetGet('id');
        }

        // $hasTransactionObjects = ['refund'];
        // if ($response->offsetExists('object')
        //     && in_array($response['object'], $hasTransactionObjects, true)
        //     && $response['->offsetExists(d'])
        // ){
        //     return $response->offsetGet('id');
        // }

        return null; //parent::getTransactionReference();
    }


    /**
     * return Omise Authorize 3DS URL (will be a bank url)
     */
    public static function getAuthorizeUrl($response)
    {
        // $data = (array)static::getData($response);
        if ($response->offsetExists('authorize_uri'))
            return $response->offsetGet('authorize_uri');

        return null;
    }

}
