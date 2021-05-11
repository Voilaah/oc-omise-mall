<?php

namespace Voilaah\OmiseMall\Classes\Payments;


Class PaymentResponse
{
    private $message;

    public function __construct($message) {
        $this->message = $message;
    }

    public function getMessage() {
        return $this->message;
    }
}
