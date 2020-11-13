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
        // 'shop_id' => 'shopId',
        // 'shop_id_label' => 'Your shop ID',
        'public_key' => 'The public key',
        'public_key_label' => 'API public key is used to create tokens via javascript.',
        'secret_key' => 'The secret key',
        'secret_key_label' => 'API secret key is used to create customers, cards and charges.',
        // 'orders_page_url' => 'URL of frontend orders page',
        // 'orders_page_url_label' => 'Example: http://site.tld/account/orders',
        'endpoint_url_label' => 'URL for sending notifications from Omise Checkout',
        // 'set_payed_virtual_order_as_complete' => 'Change the status of paid virtual orders to "Done"',
    ],
    'messages' => [
        'order_number' => 'Order #',
    ]
];
