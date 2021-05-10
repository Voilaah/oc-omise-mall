<?php namespace Voilaah\OmiseMall\Classes\Payments;

use Lang;
use Request;
use Session;
use Throwable;
use Validator;
use Omnipay\Omnipay;
use ValidationException;
use OFFLINE\Mall\Models\Order;
use OFFLINE\Mall\Models\OrderState;
use Omnipay\Common\GatewayInterface;
use OFFLINE\Mall\Models\CustomerPaymentMethod;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Classes\Payments\PaymentResult;

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

        // tokn_test_5m5y176alhhgv27jqc8
        $rules = [
            // 'token' => 'required|size:28|regex:/tok_[0-9a-zA-z]{24}/',
            'token' => 'required',
        ];

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
                $response = $this->createCard($customer, null, $gateway);
                $responseData = $response->getData();
// trace_log($responseData);
                if (!$response->isSuccessful()) {
                // if ($responseData['card'] != $this->data['token']) {
                    return $result->fail((array)$response->getData(), $response);
                }
                // should be $response->getCustomerReference()
                $customerReference = $this->getCustomerReference($response);
                $cardReference     = $response->getCardReference();

//   trace_log('Supposedly successfully added new card ' . $this->data['token'] . ' to existing customer ' . $customerReference);
                // $customerReference = $customer->omise_customer_id;
                // $cardReference     = $responseData['card'];
            } else {
                // If this is the first checkout for this customer we have to register
                // the customer and a card on Omise.
                $response = $this->createCustomer($customer, $gateway);
                if (!$response->isSuccessful()) {
                    return $result->fail((array)$response->getData(), $response);
                }
                $customerReference = $this->getCustomerReference($response);
                $cardReference     = $response->getCardReference();

                $isFirstCheckout = true;
            }

            if ($isFirstCheckout === false) {
                // Update the customer's data to reflect the order's data.
                $response = $this->updateCustomer($gateway, $customerReference, $customer);
                if (!$response->isSuccessful()) {
                    return $result->fail((array)$response->getData(), $response);
                }
            }

            $response = $this->charge($gateway, $customerReference, $cardReference);

            trace_log('successfully charged ' . $customerReference . ' with card ' . $cardReference);

        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        $data = (array)$response->getData();

        // Everything went OK, no 3DS required.
        if ($response->isSuccessful()) {
            return $this->completeOrder($result, $response);
        }

        // if (!$response->isSuccessful()) {
        //     return $result->fail($data, $response);
        // }

        if (!$useCustomerPaymentMethod) {
            $this->createCustomerPaymentMethod($customerReference, $cardReference, $data);
        }

        // trace_log("===response");
        // trace_log($data['card']);
        // trace_log($data);

        // 3DS authentication is required, redirect to Omise.
        if ($response->isPending()) {
            Session::put('mall.payment.callback', self::class);
            Session::put('mall.omise.paymentIntentReference', $data['id']);
            // Session::put('mall.omise.paymentIntentAmount', $data['amount']);
            Session::put('mall.omise.paymentIntentAmount', $this->order->total_in_currency);
            Session::put('mall.omise.paymentIntentCurrency', $data['currency']);
            Session::put('mall.omise.paymentIntentCard', $cardReference);
            Session::put('mall.omise.paymentIntentCustomer', $data['customer']);
            Session::put('mall.omise.paymentIntentOrderId', $this->order->id);
            Session::put('mall.omise.paymentIntentReturnUrl', $data['return_uri']);

            return $result->redirect($this->getAuthorizeUrl($response));
        }

        // Something went wrong! :(
        return $result->fail((array)$response->getData(), $response);

    }

    /**
     * return Omise Authorize 3DS URL (will be a bank url)
     */
    protected function getAuthorizeUrl($response)
    {
        $data = (array)$response->getData();
        if (isset($data['authorize_uri']))
            return $data['authorize_uri'];
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

        $intentReference     = Session::pull('mall.omise.paymentIntentReference');
        $intentAmount     = Session::pull('mall.omise.paymentIntentAmount');
        $intentCard     = Session::pull('mall.omise.paymentIntentCard');
        $intentOrderId     = Session::pull('mall.omise.paymentIntentOrderId');
        $intentCurrency     = Session::pull('mall.omise.paymentIntentCurrency');
        $intentCustomer     = Session::pull('mall.omise.paymentIntentCustomer');
        $intentReturnUrl     = Session::pull('mall.omise.paymentIntentReturnUrl');

        if ( ! $intentReference) {
            return $result->fail([
                'msg'   => 'Missing payment intent reference',
                'intent_reference'   => $intentReference,
            ], null);
        }

        $this->setOrder($result->order);

        $params = [
            // 'paymentIntentReference'    => $intentReference,
            'transactionReference'      => $intentReference,
            'amount'                    => $intentAmount,
            // 'card'                      => $intentCard,
            'cardReference'             => $intentCard,
            // 'customer'                  => $intentCustomer,
            'customerReference'         => $intentCustomer,
            'currency'                  => $intentCurrency,
            'description'               => 'Order-'.$intentOrderId,
            'returnUrl'                 => $intentReturnUrl,
            'return_uri'                => $intentReturnUrl,
        ];

        try {
            $response = $this->confirm($gateway, $params);

trace_log('=== CONFIRM RESPONSE');
$data = (array)$response->getData();
trace_log($data);

        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        if ( ! $response->isSuccessful()) {
            return $result->fail((array)$response->getData(), $response);
        }

        return $this->completeOrder($result, $response);
    }

    /**
     * confirm 3DS payment to Omise
     */
    private function confirm(GatewayInterface $gateway, array $parameters = array())
    {
trace_log("=== CONFIRM Request parameters");
trace_log($parameters);
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
        $data = (array)$response->getData();
trace_log('==== FINAL Response');
trace_log($data);
        $this->order->card_type                = $data['card']['brand'];
        $this->order->card_holder_name         = $data['card']['name'];
        $this->order->credit_card_last4_digits = $data['card']['last_digits'];

        $this->order->customer->omise_customer_id = $data['customer'];
        $this->order->customer->save();

        return $result->success($data, $response);
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
            'amount'            => $this->order->total_in_currency,
            'currency'          => $this->order->currency['code'],
            'returnUrl'         => $this->returnUrl(),
            'return_uri'        => $this->returnUrl(),
            'cancelUrl'         => $this->cancelUrl(),
            'cancel_uri'         => $this->cancelUrl(),
            'customerReference' => $customerReference,
            'cardReference'     => $cardReference,
            'description'       => 'Order-' . $this->order->id,
        ];
trace_log('==== INITIAL Request Charges');
trace_log($params);
        return $gateway->purchase($params)->send();
    }


    /**
     * Should be implemented in the Omnipay Omise
     * */
    protected function getCustomerReference($response)
    {
        $data = $response->getData();
        if (isset($data['object']) && $data['object'] === 'customer') {
            return $data['id'];
        }

        return null;
    }


    /**
     * Build the Omnipay Gateway for Omise.
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    protected function getGateway()
    {
        // $gateway = Omnipay::create('Omise');
        $gateway = Omnipay::create('\Omnipay\Omise\Gateway'); // CyberSource_Hosted

        $secret_key = $this->isTestMode() ? PaymentGatewaySettings::get('test_secret_key') : PaymentGatewaySettings::get('secret_key') ;

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
        $params = [
            'customerReference' => $customerReference ?? $customer->omise_customer_id,
            // 'card'              => $this->data['token'] ?? null,
            'cardReference'     => $this->data['token'] ?? null,
            // 'source'            => $this->data['token'] ?? false,
            // 'name'              => $customer->name,
        ];
        if ($customer->omise_customer_id) {
            // update existing customer with a new card
            return $gateway->updateCustomer($params)->send();
        } else {
            // new customer new card
            return $gateway->createCard($params)->send();
        }
    }



    /**
     * Create a new customer.
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
     * Update the customer.
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
        return $gateway->updateCustomer([
            'description' => 'TEST PATCH',
            'customerReference' => $customerReference,
            'email'             => $this->order->customer->user->email,
            'metadata'          => [
                'name' => $customer->name,
                'shipping'    => $this->getShippingInformation($customer),
            ]
        ])->send();
    }


    /**
     * Create a CustomerPaymentMethod.
     *
     * @param       $customerReference
     * @param       $cardReference
     * @param array $data
     */
    protected function createCustomerPaymentMethod($customerReference, $cardReference, array $data)
    {
        CustomerPaymentMethod::create([
            'name'              => trans('offline.mall::lang.order.credit_card'),
            'customer_id'       => $this->order->customer->id,
            'payment_method_id' => $this->order->payment_method_id,
            'data'              => [
                'omise_customer_id' => $customerReference,
                'omise_card_id'     => $cardReference,
                'omise_card_brand'  => $data['card']['brand'],
                'omise_card_last4'  => $data['card']['last_digits'],
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
    public function changePaymentState($response)
    {
        $responseAll = $response->all();
trace_log('=== changePaymentState object');
$object = (array)$responseAll['object'];
trace_log($object);

        $order = Order::where('payment_transaction_id', $responseAll['object']['id'])->firstOrFail();

        $this->setOrder($response->order);

        $result = new PaymentResult($this, $order);

        try {
            $response = $this->getGateway()->details([
                'transactionReference' => $responseAll['object']['id']
            ])->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        $data = (array)$response->getData();

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
     * Return URL passed to external payment services.
     *
     * The user will be redirected back to this URL once the external
     * payment service has done its work.
     *
     * @return string
     */
    // public function returnUrl(): string
    // {
    //     return Request::root() . '/omise-checkout?' . http_build_query([
    //             'return'             => 'return',
    //             'oc-mall_payment_id' => $this->getPaymentId(),
    //         ]);
    // }

    /**
     * Cancel URL passed to external payment services.
     *
     * The user will be redirected back to this URL if she cancels
     * the payment on an external payment service.
     *
     * @return string
     */
    // public function cancelUrl(): string
    // {
    //     return Request::root() . '/omise-checkout?' . http_build_query([
    //             'return'             => 'cancel',
    //             'oc-mall_payment_id' => $this->getPaymentId(),
    //         ]);
    // }

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
