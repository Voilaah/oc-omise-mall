<?php namespace Voilaah\OmiseMall\Classes\Payments;

use Lang;
use Request;
use Session;
use Input;
use OmiseCard;
use Throwable;
use Validator;
use OmiseCharge;
use OmiseCustomer;
use Omnipay\Omnipay;
use ValidationException;
use OFFLINE\Mall\Models\Order;
use OFFLINE\Mall\Models\OrderState;
use Omnipay\Common\GatewayInterface;
use OFFLINE\Mall\Models\CustomerPaymentMethod;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Classes\Payments\PaymentResult;
use Voilaah\OmiseMall\Classes\Payments\OmiseHelper;

/**
 * Testing cards:
 * Success:
 * - 4111 1111 1111 1111
 * - 4242 4242 4242 4242
 * - 5454 5454 5454 5454
 * Failed:
 * - 4111 1111 1115 0002
 * - 4111 1111 1114 0011
 */
class OmiseCheckoutProvider extends OverridePaymentProvider
{
    /**
     * The order that is being paid.
     *
     * @var \OFFLINE\Mall\Models\Order
     */
    public $order;
    /**
     * Data that is needed for the payment.
     * Card numbers, tokens, etc.
     *
     * @var array
     */
    public $data;

    /**
     * Return the display name of your payment provider.
     *
     * @return string
     */
    public function name(): string
    {
        return Lang::get('voilaah.omisemall::lang.settings_checkout.omise_checkout');
    }

    /**
     * Return a unique identifier for this payment provider.
     *
     * @return string
     */
    public function identifier(): string
    {
        return 'omise-checkout';
    }

    /**
     * Validate the given input data for this payment.
     *
     * @return bool
     * @throws \October\Rain\Exception\ValidationException
     */
    public function validate(): bool
    {
        if (isset($this->data['use_customer_payment_method'])) {
            return true;
        }

        $rules = [
            // 'token' => 'required|size:28|regex:/tok_[0-9a-zA-z]{24}/',
            'token' => 'required',
        ];
        $this->last_digits = substr(Input::get('omise_card_number'), -4);


        $validation = Validator::make($this->data, $rules);
        if ($validation->fails()) {
            trace_log($validation->getMessage());
            throw new ValidationException($validation);
        }

        return true;
    }

    /**
     * Process the payment.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function process(PaymentResult $result): PaymentResult
    {

        $response = null;
        $useCustomerPaymentMethod = $this->order->customer_payment_method;

        try {
            $gateway = $this->getGateway();

            // Session::put('mall.payment.id', str_random(8));

            $customer = $this->order->customer;
            $isFirstCheckout = false;
            $customerReference = null;
            $cardReference = null;

            // The checkout uses an existing payment method. The customer and
            // card references can be fetched from there.
            if ($useCustomerPaymentMethod) {
// trace_log('3. useCustomerPaymentMethod branch...');
                $customerReference = $this->order->customer_payment_method->data['omise_customer_id'];
                $cardReference     = $this->order->customer_payment_method->data['omise_card_id'];
            } elseif ($customer->omise_customer_id) {
// trace_log('2. existing omise customer branch...');
                // If the customer uses a new payment method but is already registered
                // on Omise, just create the new card.

                /**
                 * not needed anymore as we charge directly from
                 * the token id
                 */
                // $response = $this->createCard($customer, null, $gateway);


                // if (!OmiseHelper::isSuccessful($response)) {
                //     return $result->fail((array)$response, $response);
                // }

                /**
                 * not needed anymore as we charge directly from
                 * the token id
                 */
                // $customerReference = OmiseHelper::getCustomerReference($response);
                // $cardReference     = OmiseHelper::getCardReference($response, $this->last_digits);

//   trace_log('Supposedly successfully added new card ' . $this->data['token'] . ' to existing customer ' . $customerReference);
                // $customerReference = $customer->omise_customer_id;
                // $cardReference     = $responseData['card'];
            } else {
                // If this is the first checkout for this customer we have to register
                // the customer and a card on Omise.

// trace_log('1. first time user and card branch...');
                $response = $this->createCustomer($customer, $gateway);

                // if (!OmiseHelper::isSuccessful($response)) {
                //     return $result->fail((array)OmiseHelper::getData($response), $response);
                // }

                $customerReference = OmiseHelper::getCustomerReference($response);
                $cardReference     = OmiseHelper::getCardReference($response, null); //$response->getCardReference();

                $isFirstCheckout = true;
            }

            // if ($isFirstCheckout === false) {
            //     // Update the customer's data to reflect the order's data.
            //     $response = $this->updateCustomer($gateway, $customerReference, $customer);
            //     if (!$response->isSuccessful()) {
            //         return $result->fail((array)OmiseHelper::getData($response), $response);
            //     }
            // }


            $response = $this->charge($gateway, $customerReference, $cardReference);

        } catch (Throwable $e) {
            return $result->fail([], $e);
        }
// trace_log('== CHARGE RESPONSE');
// trace_log($response);

        $transactionReference = OmiseHelper::getTransactionReference($response);

        // trace_log('successfully charged customer:' . $customerReference . ' with card:' . $cardReference . ', transactionReference:' . $transactionReference);

        $this->order->payment_transaction_id = $transactionReference;
        $this->order->save();

        // Everything went OK, no 3DS required.
        if (OmiseHelper::isSuccessful($response)) {
            return $this->completeOrder($result, $response);
        }

        if (OmiseHelper::isFailed($response)) {
            $dataLog = [];
            $dataLog['failure_code'] = $response->offsetGet('failure_code');
            $dataLog['failure_message'] = $response->offsetGet('failure_message');
            $dataLog['msg'] = $response->offsetGet('failure_message');
            $dataResponse = OmiseHelper::buildResponse($response);
            return $result->fail((array)$dataLog, $dataResponse);
        }

        // if (!$useCustomerPaymentMethod) {
        //     $this->createCustomerPaymentMethod($customerReference, $cardReference, $response);
        // }

        // 3DS authentication is required, redirect to Omise.
        if (OmiseHelper::isPending($response)) {
            Session::put('mall.payment.callback', self::class);
            Session::put('mall.omise.paymentIntentReference', $response->offsetGet('id'));
            // Session::put('mall.omise.paymentIntentAmount', $response->offsetGet('amount'));
            Session::put('mall.omise.paymentIntentAmount', $this->order->total_in_currency*100);
            Session::put('mall.omise.paymentIntentCurrency', $response->offsetGet('currency'));
            Session::put('mall.omise.paymentIntentCard', $cardReference);
            Session::put('mall.omise.paymentIntentCustomer', $response->offsetGet('customer'));
            Session::put('mall.omise.paymentIntentOrderId', $this->order->id);
            Session::put('mall.omise.paymentIntentReturnUrl', $response->offsetGet('return_uri'));

            return $result->redirect(OmiseHelper::getAuthorizeUrl($response));
        }

        // Something went wrong! :(
        return $result->fail((array)$response, $response);

    }


    /**
     * Omise has processed the payment with 3DS and redirected the user back.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function complete(PaymentResult $result): PaymentResult
    {
        $gateway = $this->getGateway();

        $intentReference    = Session::pull('mall.omise.paymentIntentReference');
        $intentAmount       = Session::pull('mall.omise.paymentIntentAmount');
        $intentCard         = Session::pull('mall.omise.paymentIntentCard');
        $intentOrderId      = Session::pull('mall.omise.paymentIntentOrderId');
        $intentCurrency     = Session::pull('mall.omise.paymentIntentCurrency');
        $intentCustomer     = Session::pull('mall.omise.paymentIntentCustomer');
        $intentReturnUrl    = Session::pull('mall.omise.paymentIntentReturnUrl');

        if ( ! $intentReference) {
            return $result->fail([
                'msg'   => 'Missing payment intent reference',
                'intent_reference'   => $intentReference,
            ], null);
        }

        $this->setOrder($result->order);

        $params = [
            // 'paymentIntentReference'    => $intentReference,
            // 'transactionReference'      => $intentReference,
            'transaction'               => $intentReference,
            'amount'                    => $intentAmount,
            // 'card'                      => $intentCard,
            // 'cardReference'             => $intentCard,
            'customer'                  => $intentCustomer,
            // 'customerReference'         => $intentCustomer,
            'currency'                  => $intentCurrency,
            'description'               => 'Order-'.$intentOrderId,
            // 'returnUrl'                 => $intentReturnUrl,
            'return_uri'                => $intentReturnUrl,
        ];

        try {
            $response = $this->confirm($gateway, $params);

// trace_log('=== AFTER COMPLETE PURCHASE');
// trace_log($response);


        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        if ( ! OmiseHelper::isSuccessful($response)) {
            $dataLog = [];
            $dataLog['failure_code'] = $response->offsetGet('failure_code');
            $dataLog['failure_message'] = $response->offsetGet('failure_message');
            $dataLog['msg'] = $response->offsetGet('failure_message');
            $dataResponse = OmiseHelper::buildResponse($response);
            return $result->fail((array)$dataLog, $dataResponse);
        }

        return $this->completeOrder($result, $response);
    }

    /**
     * confirm 3DS payment to Omise
     */
    private function confirm(GatewayInterface $gateway, array $parameters = array())
    {
// trace_log("=== CONFIRM Request parameters");
// trace_log($parameters);
        return OmiseCharge::retrieve($parameters['transaction']);

        return $gateway->completePurchase($parameters)->send();
    }

    /**
     * Set the returned info from Omise on the Order and Customer.
     *
     * @param PaymentResult $result
     * @param Response $response
     * @return PaymentResult
     */
    protected function completeOrder(PaymentResult $result, $response)
    {
        // $data = (array)OmiseHelper::getData($response);
// trace_log('==== FINAL Response');
// trace_log($response);
        $this->order->card_type                = $response->offsetGet('card')['brand'];
        $this->order->card_holder_name         = $response->offsetGet('card')['name'];
        $this->order->credit_card_last4_digits = $response->offsetGet('card')['last_digits'];

        $this->order->customer->omise_customer_id = $response->offsetGet('customer');
        $this->order->customer->save();

        $dataLog = [];
        $dataLog['transaction'] = $response->offsetGet('id');
        $dataLog['card'] = $response->offsetGet('card')['id'];

        return $result->success($dataLog, $response);
    }




    /**
     * Charge the customer.
     *
     * @param GatewayInterface $gateway
     * @param                  $customerReference
     * @param                  $cardReference
     *
     * @return PurchaseResponse
     */
    protected function charge(GatewayInterface $gateway, $customerReference, $cardReference)
    {
        $params = [
            'amount'            => $this->order->total_in_currency * 100,
            'currency'          => $this->order->currency['code'],
            // 'returnUrl'         => $this->returnUrl(),
            'return_uri'        => $this->returnUrl(),
            // 'cancelUrl'         => $this->cancelUrl(),
            'cancel_uri'         => $this->cancelUrl(),
            // 'customer' => $customerReference,
            // 'card'     => $cardReference,
            'card'     =>                 $this->data['token'],
            // 'cardReference'     => $cardReference,
            'description'       => 'Order-' . $this->order->id,
        ];
// trace_log('==== INITIAL Request Charges');
// trace_log($params);
        return OmiseCharge::create($params);

        return $gateway->purchase($params)->send();
    }



    /**
     * Build the Omnipay Gateway for Omise.
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    protected function getGateway()
    {
        $public_key = $this->isTestMode() ? PaymentGatewaySettings::get('test_public_key') : PaymentGatewaySettings::get('public_key') ;
        $secret_key = $this->isTestMode() ? PaymentGatewaySettings::get('test_secret_key') : PaymentGatewaySettings::get('secret_key') ;

// trace_log('=== Omise keys:' . $public_key . ':' . decrypt($secret_key));

        define('OMISE_PUBLIC_KEY', $public_key);
        define('OMISE_SECRET_KEY', decrypt($secret_key));

        $gateway = Omnipay::create('\Omnipay\Omise\Gateway'); // CyberSource_Hosted

        $gateway->setApiKey(decrypt($secret_key));

        return $gateway;
    }


    /**
     * Create a new card.
     *
     * @param                  $customer
     * @param GatewayInterface $gateway
     *
     * @return mixed
     */
    protected function createCard($customer, $customerReference = null, GatewayInterface $gateway)
    {
        $customerRef = $customerReference ?? $customer->omise_customer_id;

        $params = [
            // 'customerReference' => $customerRef,
            'card'                  => $this->data['token'] ?? null,
            // 'cardReference'     => $this->data['token'] ?? null,
            // 'source'            => $this->data['token'] ?? false,
            // 'name'              => $customer->name,
        ];
        if ($customer->omise_customer_id) {
            // $response = $this->updateCustomer($gateway, $customerRef, $customer);
            // update existing customer with a new card
            $customer = OmiseCustomer::retrieve($customerRef);
            return $customer->update($params);

            return $gateway->updateCustomer($params)->send();
        } else {
            // new customer new card
            return OmiseCard::create($params);
            return $gateway->createCard($params)->send();
        }
    }


    /**
     * Create a new customer and his first card
     *
     * @param                  $customer
     * @param GatewayInterface $gateway
     *
     * @return mixed
     */
    protected function createCustomer($customer, GatewayInterface $gateway)
    {
        $description = sprintf(
            'Wunderfood Online Store Customer %s (#%d)',
            $customer->user->email,
            $customer->id
        );

        $params = [
            'description'       => $description,
            // 'cardReference'     => $this->data['token'] ?? false,
            'email'             => $this->order->customer->user->email,
            // 'card'              => $this->data['token'] ?? false,
            'metadata'    => [
                'name'          => $customer->name,
                'shipping'      => $this->getShippingInformation($customer),
            ],
        ];

// trace_log('=== CREATE CUSTOMER');
// trace_log($params);

        $customer = OmiseCustomer::create($params);
// trace_log('=== RETURNED CUSTOMER');
// trace_log($customer);

        return $customer;


        return $gateway->createCustomer([
            'description' => $description,
            'cardReference'        => $this->data['token'] ?? false,
            'email'       => $this->order->customer->user->email,
            'metadata'    => [
                'name'          => $customer->name,
                'shipping'      => $this->getShippingInformation($customer),
            ],
        ])->send();
    }

    /**
     * Update the customer for testing purpose
     *
     * @param GatewayInterface $gateway
     * @param                  $customerReference
     * @param                  $customer
     *
     * @return AbstractResponse
     */
    protected function updateCustomer(
        GatewayInterface $gateway,
        $customerReference,
        $customer
    ) {
        // trace_log('ATTEMPT PATCH Customer ' . $customerReference);
        return $gateway->updateCustomer([
            'description' => 'TEST PATCH',
            'customerReference' => $customerReference,
            // 'email'             => $this->order->customer->user->email,
            // 'metadata'          => [
            //     'name' => $customer->name,
            //     'shipping'    => $this->getShippingInformation($customer),
            // ]
        ])->send();
    }


    /**
     * Create a CustomerPaymentMethod.
     *
     * @param       $customerReference
     * @param       $cardReference
     * @param array $data
     */
    protected function createCustomerPaymentMethod($customerReference, $cardReference, $response)
    {
        // trace_log('=== createCustomerPaymentMethod...');
        CustomerPaymentMethod::create([
            'name'              => trans('offline.mall::lang.order.credit_card'),
            'customer_id'       => $this->order->customer->id,
            'payment_method_id' => $this->order->payment_method_id,
            'data'              => [
                'omise_customer_id' => $customerReference,
                'omise_card_id'     => $cardReference,
                'omise_card_brand'  => $response->offsetGet('card')['brand'],
                'omise_card_last4'  => $response->offsetGet('card')['last_digits'],
            ],
        ]);

    }

    /**
     * Get all available shipping information.
     *
     * @param $customer
     *
     * @return array
     */
    protected function getShippingInformation($customer): array
    {
        $name = $customer->shipping_address->name;
        if ($customer->shipping_address->company) {
            $name = sprintf(
                '%s (%s)',
                $customer->shipping_address->company,
                $customer->shipping_address->name
            );
        }

        return [
            'name'    => $name,
            'address' => [
                'line1'       => $customer->shipping_address->lines_array[0] ?? '',
                'line2'       => $customer->shipping_address->lines_array[1] ?? '',
                'city'        => $customer->shipping_address->city,
                'country'     => $customer->shipping_address->country->name,
                'postal_code' => $customer->shipping_address->zip,
                'state'       => optional($customer->shipping_address->state)->name,
            ],
        ];
    }
    /**
     * return if we are in test mode or live mode
     *
     * @return bool
     */
    private function isTestMode(): bool
    {
        return (bool)PaymentGatewaySettings::get('omise_test_mode');
    }


    /**
     * handle response from Omise after payment checkout is done
     *
     * @param mixed $response
     *
     * @return [type]
     */
    public function handleWebhookRequest($response)
    {
        $responseAll = $response->all();
        $data = (array)$responseAll['data'];
        // trace_log($data);
        // trace_log('=== handleWebhookRequest related to ' . $responseAll['key'] . ':' . $responseAll['id'] . ' and ' . $data['object'] . ':' . $data['id'] );
        return;

        $transactionReference = OmiseHelper::getTransactionReference($response);


        $order = Order::where('payment_transaction_id', $transactionReference)->firstOrFail();

        $this->setOrder($response->order);

        $result = new PaymentResult($this, $order);

        try {
            $response = $this->getGateway()->details([
                'transactionReference' => $transactionReference, //$responseAll['object']['id']
            ])->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        $data = (array)OmiseHelper::getData($response);

        switch ($responseAll['event']) {
            case 'payment.succeeded':
                if ($order->is_virtual === 1 and PaymentGatewaySettings::get('setPayedVirtualOrderAsComplete')) {
                    $order->order_state_id = $this->getOrderStateId(OrderState::FLAG_COMPLETE);
                    $order->save();
                }

                try {
                    \Event::fire('mall.checkout.succeeded', $result);
                } catch (Throwable $e) {
                    return null;
                }

                return $result->success($data, $response);
                break;
            case 'payment.canceled':
                $order->order_state_id = $this->getOrderStateId(OrderState::FLAG_CANCELLED);
                $order->save();

                return $result->fail($data, $response);
                break;
            case 'refund.succeeded':
                $order->order_state_id = $this->getOrderStateId(OrderState::FLAG_COMPLETE);
                $order->save();

                return $result->pending();
                break;
            case 'payment.waiting_for_capture':
                // not used
                return $result->pending();
                break;
            default:
                return $result->fail($data, $response);
        }
    }

    /**
     * Get this payment's id form the session.
     *
     * @return string
     */
    private function getPaymentId()
    {
        return Session::get('mall.payment.id');
    }

    /**
     * Return any custom backend settings fields.
     *
     * These fields will be rendered in the backend
     * settings page of your provider.
     *
     * @return array
     */
    public function settings(): array
    {
        return [
            'omise_test_mode' => [
                'label'   => 'voilaah.omisemall::lang.settings_checkout.test_mode',
                'comment' => 'voilaah.omisemall::lang.settings_checkout.test_mode_comment',
                'span'    => 'left',
                'type'    => 'switch',
            ],
            'test_public_key_section' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.test_keys'),
                'span'    => 'left',
                'type'    => 'section',
            ],
            'live_public_key_section' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.live_keys'),
                'span'    => 'auto',
                'type'    => 'section',
            ],
            'test_public_key' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.test_public_key'),
                'comment' => Lang::get('voilaah.omisemall::lang.settings_checkout.test_public_key_label'),
                'span'    => 'left',
                'type'    => 'text',
            ],
            'public_key' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.public_key'),
                'comment' => Lang::get('voilaah.omisemall::lang.settings_checkout.public_key_label'),
                'span'    => 'auto',
                'type'    => 'text',
            ],
            'test_secret_key' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.test_secret_key'),
                'comment' => Lang::get('voilaah.omisemall::lang.settings_checkout.test_secret_key_label'),
                'span'    => 'auto',
                'type'    => 'text',
            ],
            'secret_key' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.secret_key'),
                'comment' => Lang::get('voilaah.omisemall::lang.settings_checkout.secret_key_label'),
                'span'    => 'auto',
                'type'    => 'text',
            ],
            'endpointUrl' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.endpoint_url_label'),
                'span'    => 'left',
                'type'    => 'partial',
                'path'    => '$/voilaah/omisemall/view/_endpoint_checkout_url.htm'
            ]
        ];
    }


    /**
     * Setting keys returned from this method are stored encrypted.
     *
     * Use this to store API tokens and other secret data
     * that is needed for this PaymentProvider to work.
     *
     * @return array
     */
    public function encryptedSettings(): array
    {
        return [ 'test_secret_key', 'secret_key'];
    }

}
