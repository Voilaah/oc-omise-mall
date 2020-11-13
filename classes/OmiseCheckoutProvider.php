<?php namespace Voilaah\OmiseMall\Classes;

use OFFLINE\Mall\Classes\Payments\PaymentProvider;
use OFFLINE\Mall\Classes\Payments\PaymentResult;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Models\OrderState;
use OFFLINE\Mall\Models\Order;
use Omnipay\Omnipay;
use Throwable;
use Session;
use Lang;


class OmiseCheckoutProvider extends PaymentProvider
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

        $gateway->setApiKey(decrypt(PaymentGatewaySettings::get('secret_key')));

        return $gateway;
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
            'endpointUrl' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.endpoint_url_label'),
                'span'    => 'left',
                'type'    => 'partial',
                'path'    => '$/voilaah/omisemall/view/_endpoint_checkout_url.htm'
            ],
            'public_key' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.public_key'),
                'comment' => Lang::get('voilaah.omisemall::lang.settings_checkout.public_key_label'),
                'span'    => 'left',
                'type'    => 'text',
            ],
            'secret_key' => [
                'label'   => Lang::get('voilaah.omisemall::lang.settings_checkout.secret_key'),
                'comment' => Lang::get('voilaah.omisemall::lang.settings_checkout.secret_key_label'),
                'span'    => 'left',
                'type'    => 'text',
            ],
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
        return ['secret_key'];
    }

}
