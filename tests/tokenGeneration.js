$(function () {

    var omise_params = {
        'key': '{{ cart.payment_method.settings.omise_test_mode ? cart.payment_method.settings.test_public_key : cart.payment_method.settings.public_key }}',
    };

    {# Lazy load omise.js to ensure everything works when changing the payment method. #}
    var s = document.createElement('script');
    s.type = 'text/javascript';
    s.src = 'https://cdn.omise.co/omise.js';
    s.onload = initOmise;

    document.head.appendChild(s)

    function initOmise () {

        document.getElementById('omise_card_number').oninput = function() {
            this.value = cc_format(this.value)
        }

        if(Omise){
            Omise.setPublicKey(omise_params.key);

            var card = document.getElementById('card-element');

            card.addEventListener('change', function (event) {
                var displayError = document.getElementById('card-errors');
                if (event.error) {
                    displayError.textContent = event.error.message;
                } else {
                    displayError.textContent = '';
                }
            });

            window.Mall.Callbacks.Checkout.Omise = function () {
                return new Promise(function (resolve, reject) {
                    generateToken().then(resolve).catch(reject)
                    }
                )
            }

            var form = document.getElementById('mall-payment-form');
            if (form) {
                form.addEventListener('submit', generateToken);
            }

            var input = document.getElementById('omise_token')

            function generateToken (event) {
                if (event) {
                    event.preventDefault();
                }
                var errorElement = document.getElementById('card-errors');
                errorElement.classList.remove('visible')

                if (form) {
                    var submit = form.querySelector('[type="submit"]')
                    if (submit) {
                        submit.classList.add('oc-loading')
                        submit.disabled = true
                    }
                }

                let errors            = [],
                    omise_card        = {},
                    omise_card_fields = {
                        'name'             : $( '#omise_card_name' ),
                        'number'           : $( '#omise_card_number' ),
                        'expiration_month' : $( '#omise_card_expiration_month' ),
                        'expiration_year'  : $( '#omise_card_expiration_year' ),
                        'security_code'    : $( '#omise_card_security_code' )
                    };

                $.each( omise_card_fields, function( index, field ) {
                    omise_card[ index ] = field.val();
                    if ( "" === omise_card[ index ] ) {
                        errors.push( omise_params[ 'required_card_' + index ] );
                    }
                } );
                return new Promise(function (resolve, reject) {
                    Omise.createToken("card", omise_card, function (statusCode, response) {
                        if (statusCode == 200) {
                            console.log('omise it does work ' );
                            /*input = response.id;*/
                            omiseTokenHandler(response);
                            resolve();

                        } else {
                            console.log('omise it does not work ' );

                            if ( response.object && 'error' === response.object && 'invalid_card' === response.code ) {
                                console.log( omise_params.invalid_card + "<br/>" + response.message );
                            } else if(response.message){
                                console.log( omise_params.cannot_create_token + "<br/>" + response.message );
                            }else if(response.responseJSON && response.responseJSON.message){
                                console.log( omise_params.cannot_create_token + "<br/>" + response.responseJSON.message );
                            }else if(response.status==0){
                                console.log( omise_params.cannot_create_token + "<br/>" + omise_params.cannot_connect_api + omise_params.retry_checkout );
                            }else {
                                console.log( omise_params.cannot_create_token + "<br/>" + omise_params.retry_checkout );
                            }

                            if (submit) {
                                submit.classList.remove('oc-loading')
                                submit.disabled = false
                            }
                            console.log(response);
                            alert(response.code + "\r\n" + response.message);
                            reject(response.code + "\r\n" + response.message);

                        };
                    });
                })
            }

            function omiseTokenHandler(token) {
                input.value = token.id
                var $form = $('#mall-payment-form')

                if ($form.length) {
                    var form = document.getElementById('mall-payment-form');
                    var submit = form.querySelector('.oc-loading')

                    $form.request('onSubmit', {
                        error: function(res) {
                            if (submit) {
                                submit.classList.remove('oc-loading')
                                submit.disabled = false
                            }
                            this.error(res)
                        }
                    })
                }
            }
        }
    }
});
