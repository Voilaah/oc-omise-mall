# oc-omise-mall
Omise Payment Gateway (Singapore) for October CMS Mall Plugin (provides CC checkout and OCBC PayNow provider)


### Omise Omnipay Installation

You might need to install this package from the root of your October CMS installation.

`
COMPOSER_MEMORY_LIMIT=-1 composer require league/omnipay:^3
COMPOSER_MEMORY_LIMIT=-1 composer require dilab/omnipay-omise
`

https://github.com/Spacebib/omnipay-omise



https://www.omise.co/api-testing

These card numbers can be used to generate successful charges.

| Number                | Brand |
| --------------------- | -----:|
| 4242 4242 4242 4242	| Visa       |
| 4111 1111 1111 1111	| Visa       |
| 5555 5555 5555 4444	| Mastercard |
| 5454 5454 5454 5454	| Mastercard |
| 3530 1113 3330 0000	| JCB        |
| 3566 1111 1111 1113	| JCB        |
| 3782 8224 6310 005	| Amex       |

These card numbers can be used to create a charge with a specific failure_code. See the Charges API for more information about these failure codes.

| Number                | Brand | Failure | Code |
| --------------------- | ----- |----- |----- |
| 4111 1111 1114 0011 | Visa |	| insufficient_fund |
| 5555 5511 1111 0011 | Mastercard |	| insufficient_fund |
| 3530 1111 1119 0011 | JCB |	| insufficient_fund |
| 4111 1111 1113 0012 | Visa |	| stolen_or_lost_card |
| 5555 5511 1110 0012 | Mastercard |	| stolen_or_lost_card |
| 3530 1111 1118 0012 | JCB |	| stolen_or_lost_card |
| 4111 1111 1112 0013 | Visa |	| failed_processing |
| 5555 5511 1119 0013 | Mastercard |	| failed_processing |
| 3530 1111 1117 0013 | JCB |	| failed_processing |
| 4111 1111 1111 0014 | Visa |	| payment_rejected |
| 5555 5511 1118 0014 | Mastercard |	| payment_rejected |
| 3530 1111 1116 0014 | JCB |	| payment_rejected |
| 4111 1111 1119 0016 | Visa |	| failed_fraud_check |
| 5555 5511 1116 0016 | Mastercard |	| failed_fraud_check |
| 3530 1111 1114 0016 | JCB |	| failed_fraud_check |
| 4111 1111 1118 0017 | Visa |	| invalid_account_number |
| 5555 5511 1115 0017 | Mastercard |	| invalid_account_number |
| 3530 1111 1113 0017 | JCB |	| invalid_account_number |
| 4111 1111 1116 0001 | Visa |	| invalid_security_code |
| 5555 5511 1113 0001 | Mastercard |	| invalid_security_code |
| 3530 1111 1111 0001 | JCB |	| invalid_security_code |
