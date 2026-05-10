# JunoPay OpenCart Extension

OpenCart 3 payment extension for Juno Pay Server.

## Install

Copy the repository contents into an OpenCart 3 installation root so these paths land under the existing `admin/` and `catalog/` directories:

- `admin/controller/extension/payment/junopay.php`
- `admin/language/en-gb/extension/payment/junopay.php`
- `admin/view/template/extension/payment/junopay.twig`
- `catalog/controller/extension/payment/junopay.php`
- `catalog/language/en-gb/extension/payment/junopay.php`
- `catalog/model/extension/payment/junopay.php`
- `catalog/view/theme/default/template/extension/payment/junopay.twig`
- `catalog/view/theme/default/template/extension/payment/junopay_invoice.twig`

Then enable `JunoPay` in OpenCart admin under Extensions > Payments and configure:

- API base URL
- Merchant API key
- Zatoshis per currency unit
- Order status
