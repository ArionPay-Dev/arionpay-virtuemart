# ArionPay for VirtueMart

Crypto payments (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) for VirtueMart through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

For VirtueMart 4 on Joomla 4 or 5 (VirtueMart itself does not run on Joomla 6 yet), PHP 7.4+. Tested on VirtueMart 4.8.2 with Joomla 5.4.10 and PHP 8.3. The tests covered guest checkout, coming back before and after paying, a lost webhook, paying later from the order page, payment tolerance, Payment Radar, late payments, status notes and webhook checks.

## Install

1. Joomla admin: *System > Install > Extensions*, upload `plg_vmpayment_arionpay-1.0.0.zip`.
2. *VirtueMart > Payment Methods > New*: name it (for example "Crypto (Bitcoin, USDT and more)"), set **Published** to Yes, choose **VM Payment - ArionPay** and **Save**.
3. Open the **Configuration** tab of the method and fill in:

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |
| Test mode | Yes while you try it (no real money); No for real payments | |
| ArionPay address | leave empty | |
| Payment currency | your shop currency, or one ArionPay takes | |

Save. Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (`index.php?option=com_virtuemart&view=vmplg&task=notify&tmpl=component` on your site).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## How orders change

- At checkout the order is saved as **Pending** and the customer goes to the ArionPay page.
- When ArionPay confirms the full payment, the order becomes **Confirmed** and VirtueMart sends its usual email. The order history shows the ArionPay invoice, the coin and network, and notes such as "paid late".
- When the customer comes back, the plugin asks ArionPay directly. If the invoice is paid, the order is confirmed (this also covers a lost webhook). If it isn't paid yet, the customer sees a **Pay with crypto** button.
- A customer who left can pay later: the order page shows **Pay with crypto** while the order is Pending. The same ArionPay page opens while it is still open.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically; anything else you decide. If you accept it, the order is confirmed here too.
- A late payment still confirms the order.
- "Payment seen", partial, expired and "needs review" updates become notes in the order history.
- An order is confirmed only by the invoice made for it: same amount and currency, and test or live like the method. A second paid invoice for an already confirmed order adds a note about a possible double payment.

The statuses are settings of the method (**Order status while waiting** and **Order status when paid**, default Pending and Confirmed).

## Good to know

- ArionPay takes USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR. For other payment currencies the method is not offered.
- Fees, minimum and maximum amounts, and countries work like any VirtueMart payment method.
- The secrets are stored like VirtueMart's own PayPal plugin does. As with every Joomla password field, the settings page has them in its HTML for administrators.
- Refunds are made from your own wallet; then set the order to Refunded by hand.
- The plugin sends ArionPay the order number, amount, currency and shop name. It does not send the customer's name or email.
- VirtueMart 4.8.2 with the "bs3" layout setting is missing one of its own order-page files. If your order page shows an error, set *VirtueMart > Configuration > Templates* to Bootstrap 5.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
