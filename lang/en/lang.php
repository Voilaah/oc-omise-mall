<?php return [
    'plugin' => [
        'name' => 'Omise Payment Gateway for Mall',
        'description' => 'Omise Payment Gateway (Singapore) for Mall Plugin (provides CC checkout and OCBC PayNow provider)',
    ],
    'settings_paynow' => [
        'omise_paynow' => 'Omise PayNow',
        'endpoint_url_label' => 'URL for sending notifications from Omise PayNow',
    ],
    'settings_checkout' => [
        'omise_checkout' => 'Omise Checkout',
        'test_mode' => 'Test mode',
        'test_mode_comment' => 'Enabling test mode means that all your transactions will be performed under the Omise test account.',

        'test_keys' => 'The keys for test',
        'live_keys' => 'The keys for live',
        'test_public_key' => 'The public key for test',
        'test_public_key_label' => 'The public key is used to create tokens via javascript.',
        'test_secret_key' => 'The secret key for test',
        'test_secret_key_label' => 'The secret key is used to create customers, cards and charges.',
        // 'orders_page_url' => 'URL of frontend orders page',
        'public_key' => 'The public key for live',
        'public_key_label' => 'The public key is used to create tokens via javascript.',
        'secret_key' => 'The secret key for live',
        'secret_key_label' => 'The secret key is used to create customers, cards and charges.',
        // 'orders_page_url' => 'URL of frontend orders page',
        // 'orders_page_url_label' => 'Example: http://site.tld/account/orders',
        'endpoint_url_label' => 'URL for sending notifications from Omise Checkout',
        // 'set_payed_virtual_order_as_complete' => 'Change the status of paid virtual orders to "Done"',
    ],
    'messages' => [
        'order_number' => 'Order #',
    ]
];
