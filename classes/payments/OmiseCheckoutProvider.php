<?php namespace Voilaah\OmiseMall\Classes\Payments;

use OFFLINE\Mall\Classes\Payments\PaymentResult;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Models\OrderState;
use OFFLINE\Mall\Models\Order;
use Omnipay\Omnipay;
use Throwable;
use Session;
use Lang;
use Validator;

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
            'token' => 'required|size:28|regex:/tok_[0-9a-zA-z]{24}/',
        ];

        $validation = Validator::make($this->data, $rules);
        if ($validation->fails()) {
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
        $gateway = $this->getGateway();

        $response = null;

        $customer        = $this->order->customer;

        try {
            $response = $gateway->purchase([
                'amount'        => $this->order->total_in_currency,
                'currency'      => $this->order->currency['code'],
                'capture'       => true,
                'returnUrl'     => $this->returnUrl(),
                'cancelUrl'     => $this->cancelUrl(),
                'transactionId' => uniqid('', true),
                'description'   => Lang::get('iweb.yandexcheckoutmall::lang.messages.order_number') . $this->order->order_number,
                'metadata'      => array(
                    'order_id'      => $this->order->id,
                ),
            ])->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        // PayPal has to return a RedirectResponse if everything went well
        if (!$response->isRedirect()) {
            return $result->fail((array)$response->getData(), $response);
        }

        Session::put('mall.payment.callback', self::class);
        Session::put('mall.omise-checkout.transactionReference', $response->getTransactionReference());

        $this->setOrder($result->order);
        $result->order->payment_transaction_id = $response->getTransactionReference();
        $result->order->save();

        return $result->redirect($response->getRedirectResponse()->getTargetUrl());
    }

        /**
     * Y.K. has processed the payment and redirected the user back.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function complete(PaymentResult $result): PaymentResult
    {

    }

    /**
     * Build the Omnipay Gateway for Omise.
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    protected function getGateway()
    {
        $gateway = Omnipay::create('Omise');

        $secret_key = $this->isTestMode() ? PaymentGatewaySettings::get('test_secret_key') : PaymentGatewaySettings::get('secret_key') ;

        $gateway->setApiKey(decrypt($secret_key));

        return $gateway;
    }


    /**
     * return if we are in test mode or live mode
     *
     * @return boolean
     */
    private function isTestMode(): boolean
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
