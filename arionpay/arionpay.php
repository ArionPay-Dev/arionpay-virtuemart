<?php
/**
 * ArionPay for VirtueMart 4: crypto payments (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) paid straight to the
 * merchant's own wallet.
 *
 * The order is saved as pending and the customer pays on the ArionPay page. The order is confirmed only by ArionPay
 * itself: the signed webhook (plgVmOnPaymentNotification) or a signed invoice read when the customer comes back.
 * Whether an invoice is paid is decided by ArionPay alone; this plugin never compares crypto amounts.
 *
 * @license MIT
 */
defined('_JEXEC') or die('Restricted access');

if (!class_exists('\ArionPay\Client', true)) {
    require_once __DIR__ . '/lib/arionpay-php/autoload.php';
}

class plgVmPaymentArionpay extends vmPSPlugin
{
    const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];
    const OPEN = ['pending', 'paid_partial', 'claim_pending'];
    const MAX_BODY_BYTES = 262144;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        $this->setConvertable(['min_amount', 'max_amount', 'cost_per_transaction', 'cost_min_transaction']);
        // No setCryptedFields(): VirtueMart's encryption needs a configured Safe Path and silently saves `false`
        // without it, which would lose the keys. Stored like VirtueMart's own PayPal plugin.
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment ArionPay Table');
    }

    public function getTableSQLFields()
    {
        return [
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => "decimal(15,5) NOT NULL DEFAULT '0.00000'",
            'payment_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_min_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)',
            'arionpay_invoice_id' => 'char(24)',
            'arionpay_invoice_url' => 'varchar(512)',
            'arionpay_amount' => 'varchar(32)',
            'arionpay_test' => 'tinyint(1)',
            'arionpay_attempt' => 'int(1)',
            'arionpay_paid_invoice' => 'char(24)',
            'arionpay_seen' => 'text',
        ];
    }

    // ------------------------------------------------------------------ checkout

    /** Offer the method only when the payment currency is one ArionPay takes and the keys are set. */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        if (!in_array($this->currencyCode($method), self::CURRENCIES, true) || trim((string) $method->api_key) === '' || trim((string) $method->api_secret) === '') {
            return false;
        }
        return parent::checkConditions($cart, $method, $cart_prices);
    }

    /** The order is saved: make (or reuse) the ArionPay invoice and send the customer to it. */
    public function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        vmLanguage::loadJLang('com_virtuemart', true);
        $bt = $order['details']['BT'];
        $currency = $this->currencyCode($method);
        $total = vmPSPlugin::getAmountInCurrency($bt->order_total, $method->payment_currency);
        $amount = number_format((float) $total['value'], 2, '.', '');

        $dbValues = [
            'order_number' => $bt->order_number,
            'virtuemart_order_id' => $bt->virtuemart_order_id,
            'virtuemart_paymentmethod_id' => (int) $bt->virtuemart_paymentmethod_id,
            'payment_name' => $this->renderPluginName($method),
            'payment_order_total' => $amount,
            'payment_currency' => $currency,
            'cost_per_transaction' => $method->cost_per_transaction,
            'cost_min_transaction' => $method->cost_min_transaction,
            'cost_percent_total' => $method->cost_percent_total,
            'tax_id' => $method->tax_id,
            'arionpay_amount' => $amount,
            'arionpay_test' => $this->isTest($method) ? 1 : 0,
            'arionpay_attempt' => 1,
        ];
        $this->storePSPluginInternalData($dbValues);

        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = $this->statusPending($method);
        $order['customer_notified'] = 0;
        $order['comments'] = '';
        $modelOrder->updateStatusForOneOrder($bt->virtuemart_order_id, $order, true);

        try {
            $url = $this->paymentPageUrl($method, $bt->order_number);
        } catch (\Throwable $e) {
            $this->logInfo('ArionPay: could not start the payment for order ' . $bt->order_number . ': ' . $e->getMessage(), 'error');
            vmError(vmText::_('VMPAYMENT_ARIONPAY_ERROR_START'));
            return false;
        }
        $cart->emptyCart();
        JFactory::getApplication()->redirect($url ?? $this->responseUrl($bt->order_number, (int) $bt->virtuemart_paymentmethod_id));
        return true;
    }

    /**
     * The open ArionPay invoice of this order when it still fits, otherwise a new one. Null when ArionPay already has
     * the order as paid (it is then confirmed here too).
     */
    private function paymentPageUrl($method, $orderNumber)
    {
        $row = $this->row($orderNumber);
        if (!$row) {
            throw new \RuntimeException('No payment row for order ' . $orderNumber);
        }
        $client = $this->client($method);
        $attempt = max(1, (int) $row->arionpay_attempt);
        if (!empty($row->arionpay_invoice_id)) {
            try {
                $read = $client->getInvoice($row->arionpay_invoice_id);
            } catch (\ArionPay\ApiException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
                $read = ['status' => 'missing', 'raw' => []];
            }
            if ($read['status'] === 'paid') {
                $this->confirmPaid($method, $orderNumber, $read, 'signed read');
                return null;
            }
            $expires = isset($read['raw']['expiresAt']) ? strtotime((string) $read['raw']['expiresAt']) : 0;
            if (in_array($read['status'], self::OPEN, true) || ($read['status'] === 'new' && ($expires === 0 || $expires > time() + 300))) {
                return $row->arionpay_invoice_url;
            }
            $attempt++;
        }
        $test = (bool) $row->arionpay_test;
        for ($tries = 0; $tries < 20; $tries++, $attempt++) {
            $created = $client->createInvoice([
                'amount' => $row->arionpay_amount,
                'currency' => $row->payment_currency,
                'orderId' => (string) $orderNumber,
                'description' => mb_substr(JFactory::getConfig()->get('sitename') . ' order ' . $orderNumber, 0, 500),
                'successUrl' => $this->responseUrl($orderNumber, (int) $row->virtuemart_paymentmethod_id),
                'cancelUrl' => JUri::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginUserPaymentCancel&on=' . rawurlencode($orderNumber) . '&pm=' . (int) $row->virtuemart_paymentmethod_id,
                'callbackUrl' => JUri::root() . 'index.php?option=com_virtuemart&view=vmplg&task=notify&tmpl=component',
                'metadata' => [
                    'vm_order_number' => (string) $orderNumber,
                    'vm_order_id' => (int) $row->virtuemart_order_id,
                    'vm_amount' => (string) $row->arionpay_amount,
                    'vm_currency' => (string) $row->payment_currency,
                    'vm_site' => $this->siteId(),
                    'platform' => 'virtuemart',
                ],
                'idempotencyKey' => 'vm-' . $this->siteId() . '-' . $orderNumber . '-' . str_replace('.', '', (string) $row->arionpay_amount) . $row->payment_currency . ($test ? '-test' : '') . '-' . $attempt,
                'test' => $test,
            ]);
            if ($created['status'] === 'paid') {
                return null;
            }
            $expires = $created['expiresAt'] ? strtotime((string) $created['expiresAt']) : 0;
            if (in_array($created['status'], self::OPEN, true) || ($created['status'] === 'new' && ($expires === 0 || $expires > time() + 300))) {
                $this->updateRow($row->id, ['arionpay_invoice_id' => $created['id'], 'arionpay_invoice_url' => $created['invoiceUrl'], 'arionpay_attempt' => $attempt]);
                return $created['invoiceUrl'];
            }
        }
        throw new \RuntimeException('Too many finished ArionPay invoices for this order.');
    }

    /** The customer came back from the ArionPay page: show where the payment stands (a signed read, never the URL). */
    public function plgVmOnPaymentResponseReceived(&$html)
    {
        $pm = vRequest::getInt('pm', 0);
        $orderNumber = vRequest::getString('on', '');
        if (!($method = $this->getVmPluginMethod($pm)) || !$this->selectedThisElement($method->payment_element)) {
            return null;
        }
        $row = $orderNumber !== '' ? $this->row($orderNumber) : null;
        if (!$row || empty($row->arionpay_invoice_id)) {
            return null;
        }
        vmLanguage::loadJLang('com_virtuemart');
        $safeNumber = htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8');
        try {
            $read = $this->client($method)->getInvoice($row->arionpay_invoice_id);
        } catch (\Throwable $e) {
            $read = ['status' => 'unknown'];
        }
        if ($read['status'] === 'paid') {
            $this->confirmPaid($method, $orderNumber, $read, 'signed read on return');
            $html = '<p>' . vmText::sprintf('VMPAYMENT_ARIONPAY_RETURN_PAID', $safeNumber) . '</p>';
        } elseif (in_array($read['status'], self::OPEN, true)) {
            $html = '<p>' . vmText::sprintf('VMPAYMENT_ARIONPAY_RETURN_PENDING', $safeNumber) . '</p>';
        } else {
            $html = '<p>' . vmText::sprintf('VMPAYMENT_ARIONPAY_RETURN_NOT_PAID', $safeNumber) . '</p>'
                . '<p><a class="vm-button-correct" href="' . htmlspecialchars((string) $row->arionpay_invoice_url, ENT_QUOTES, 'UTF-8') . '">' . vmText::_('VMPAYMENT_ARIONPAY_PAY_NOW') . '</a></p>';
        }
        $html .= $this->orderLinkHtml((int) $row->virtuemart_order_id);
        return true;
    }

    /** The customer left the ArionPay page without paying: the order is kept (a late payment still confirms it). */
    public function plgVmOnUserPaymentCancel()
    {
        $pm = vRequest::getInt('pm', 0);
        if (!($method = $this->getVmPluginMethod($pm)) || !$this->selectedThisElement($method->payment_element)) {
            return null;
        }
        vmLanguage::loadJLang('com_virtuemart');
        vmInfo(vmText::_('VMPAYMENT_ARIONPAY_CANCELLED'));
        return true;
    }

    /** On the customer's order page: a "Pay with crypto" button while the order still waits for payment. */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
        if (!$this->selectedThisByMethodId($virtuemart_paymentmethod_id) || !($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return;
        }
        $order = VmModel::getModel('orders')->getOrder($virtuemart_order_id);
        if (empty($order['details']['BT']) || $order['details']['BT']->order_status !== $this->statusPending($method)) {
            return;
        }
        try {
            $url = $this->paymentPageUrl($method, $order['details']['BT']->order_number);
        } catch (\Throwable $e) {
            return;
        }
        if ($url !== null) {
            $payment_name .= '<br /><a class="vm-button-correct" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . vmText::_('VMPAYMENT_ARIONPAY_PAY_NOW') . '</a>';
        }
    }

    // ------------------------------------------------------------------ webhook

    /** index.php?option=com_virtuemart&view=vmplg&task=notify&tmpl=component (called for every payment plugin). */
    public function plgVmOnPaymentNotification(&$html = '')
    {
        $header = isset($_SERVER['HTTP_ARIONPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_ARIONPAY_SIGNATURE'] : '';
        if ($header === '') {
            return null; // not an ArionPay webhook: another payment plugin's notification
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            $this->respond(413, ['error' => 'too large']);
        }
        $peek = json_decode($raw, true);
        $meta = (array) ($peek['data']['invoice']['metadata'] ?? []);
        $orderNumber = (string) ($meta['vm_order_number'] ?? '');
        $row = $orderNumber !== '' ? $this->row($orderNumber) : null;
        // The secret belongs to the payment method of the order (or any ArionPay method for "Send test webhook").
        $method = $row ? $this->getVmPluginMethod($row->virtuemart_paymentmethod_id) : $this->firstMethod();
        if (!$method || !$this->selectedThisElement($method->payment_element)) {
            $this->respond(200, ['received' => true, 'ignored' => 'no ArionPay payment method']);
        }
        try {
            $event = \ArionPay\Webhook::verify($raw, $header, trim((string) $method->webhook_secret));
        } catch (\ArionPay\SignatureException $e) {
            $this->logInfo('ArionPay webhook refused: ' . $e->getMessage(), 'warning');
            $this->respond(401, ['error' => 'invalid signature']);
        }
        if (\ArionPay\Webhook::isTestEvent($event)) {
            $this->logInfo('ArionPay test webhook received (' . $event['type'] . '). Nothing changed.', 'message');
            $this->respond(200, ['received' => true, 'test' => true]);
        }
        $invoice = $event['data']['invoice'];
        if (!$row || ($meta['vm_site'] ?? '') !== $this->siteId()) {
            $this->respond(200, ['received' => true, 'ignored' => 'not an order of this shop']);
        }
        if ((bool) ($invoice['test'] ?? false) !== (bool) $row->arionpay_test) {
            $this->respond(409, ['error' => 'test/live mismatch']);
        }
        if ($event['type'] === 'invoice.paid') {
            $result = $this->confirmPaid($method, $orderNumber, $invoice, 'webhook');
            $this->respond($result === 'locked' ? 503 : 200, ['received' => true, 'result' => $result]);
        }
        $texts = [
            'invoice.pending' => 'ArionPay: payment seen for invoice %s, waiting for network confirmations.',
            'invoice.partial' => 'ArionPay: partial payment for invoice %s. The order is confirmed only when it is fully paid.',
            'invoice.needs_review' => 'ArionPay flagged invoice %s for review (Payment Radar). Decide in your ArionPay dashboard.',
            'invoice.expired' => 'ArionPay invoice %s expired without full payment. A late payment still confirms the order.',
        ];
        if (isset($texts[$event['type']])) {
            $this->noteOnce($row, (string) $event['id'], ($invoice['test'] ?? false ? '[TEST] ' : '') . sprintf($texts[$event['type']], $invoice['id']));
        }
        $this->respond(200, ['received' => true]);
    }

    /**
     * Confirm the order for a paid ArionPay invoice. Safe to call many times (under a database lock). Returns
     * confirmed | duplicate | already_done | mismatch | not_waiting | locked.
     */
    private function confirmPaid($method, $orderNumber, array $invoice, $source)
    {
        $row = $this->row($orderNumber);
        $meta = (array) ($invoice['metadata'] ?? []);
        $paid = is_numeric($invoice['amountFiat'] ?? null) ? number_format((float) $invoice['amountFiat'], 2, '.', '') : '';
        if (!$row || ($meta['vm_order_number'] ?? '') !== (string) $orderNumber || ($meta['vm_site'] ?? '') !== $this->siteId()
            || $paid !== (string) $row->arionpay_amount || strtoupper((string) ($invoice['currency'] ?? '')) !== strtoupper((string) $row->payment_currency)) {
            if ($row) {
                $this->noteOnce($row, 'mismatch-' . ($invoice['id'] ?? ''), 'ArionPay invoice ' . ($invoice['id'] ?? '') . ' is paid, but its amount or currency does not match this order. NOT confirmed: check it in your ArionPay dashboard.');
            }
            return 'mismatch';
        }
        $db = JFactory::getDbo();
        $lock = 'vm_arionpay_' . (int) $row->virtuemart_order_id;
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 20)');
        if ((string) $db->loadResult() !== '1') {
            return 'locked';
        }
        try {
            $row = $this->row($orderNumber);
            $modelOrder = VmModel::getModel('orders');
            $order = $modelOrder->getOrder((int) $row->virtuemart_order_id);
            $status = $order['details']['BT']->order_status;
            if (!empty($row->arionpay_paid_invoice)) {
                if ($row->arionpay_paid_invoice === $invoice['id']) {
                    return 'duplicate';
                }
                $this->noteOnce($row, 'again-' . $invoice['id'], 'Another ArionPay invoice (' . $invoice['id'] . ') for this order was paid too. Possible double payment: check your wallet and refund it.');
                return 'already_done';
            }
            if ($status !== $this->statusPending($method) && $status !== 'P' && $status !== 'U') {
                $this->noteOnce($row, 'state-' . $invoice['id'], 'ArionPay invoice ' . $invoice['id'] . ' is paid, but the order status is "' . $status . '". NOT changed: check it.');
                return 'not_waiting';
            }
            $this->updateRow($row->id, ['arionpay_paid_invoice' => $invoice['id']]);
            $comment = (!empty($invoice['test']) ? '[TEST - no real money] ' : '') . 'ArionPay invoice ' . $invoice['id'] . ' is paid (' . $paid . ' ' . $row->payment_currency . ')';
            if (!empty($invoice['asset'])) {
                $comment .= '; ' . trim(($invoice['paidCrypto'] ?? '') . ' ' . $invoice['asset'] . (!empty($invoice['network']) ? ' on ' . $invoice['network'] : ''));
            }
            foreach (['late' => 'paid late', 'underpaidWithinTolerance' => 'slightly under, inside your payment tolerance', 'overpaid' => 'overpaid', 'manual' => 'accepted by hand in ArionPay'] as $flag => $text) {
                if (!empty($invoice[$flag])) {
                    $comment .= '; ' . $text;
                }
            }
            $update = ['order_status' => $this->statusSuccess($method), 'customer_notified' => 1, 'comments' => $comment . ' [' . $source . '].'];
            $modelOrder->updateStatusForOneOrder((int) $row->virtuemart_order_id, $update, true);
            return 'confirmed';
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
            $db->loadResult();
        }
    }

    // ------------------------------------------------------------------ helpers

    private function client($method)
    {
        $base = trim((string) $method->base_url);
        return new \ArionPay\Client(trim((string) $method->api_key), trim((string) $method->api_secret), [
            'baseUrl' => $base !== '' ? $base : \ArionPay\Client::DEFAULT_BASE_URL,
            'appInfo' => 'virtuemart/' . (defined('VM_VERSION') ? VM_VERSION : (class_exists('vmVersion') ? vmVersion::$RELEASE : '?')) . ' arionpay-vm/1.0.0',
        ]);
    }

    private function isTest($method)
    {
        return (string) $method->test_mode !== '0';
    }

    private function statusPending($method)
    {
        return !empty($method->status_pending) ? $method->status_pending : 'P';
    }

    private function statusSuccess($method)
    {
        return !empty($method->status_success) ? $method->status_success : 'C';
    }

    /** The invoice currency: the method's payment currency, else the vendor currency (sets $method->payment_currency). */
    private function currencyCode($method)
    {
        self::getPaymentCurrency($method);
        return strtoupper((string) shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3'));
    }

    private function responseUrl($orderNumber, $pm)
    {
        return JUri::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&on=' . rawurlencode($orderNumber) . '&pm=' . (int) $pm;
    }

    private function orderLinkHtml($orderId)
    {
        $order = VmModel::getModel('orders')->getOrder($orderId);
        if (empty($order['details']['BT'])) {
            return '';
        }
        $bt = $order['details']['BT'];
        $link = JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $bt->order_number . '&order_pass=' . $bt->order_pass, false);
        return '<p><a class="vm-button-correct" href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a></p>';
    }

    private function row($orderNumber)
    {
        $db = JFactory::getDbo();
        $db->setQuery('SELECT * FROM ' . $db->quoteName($this->_tablename) . ' WHERE ' . $db->quoteName('order_number') . ' = ' . $db->quote((string) $orderNumber) . ' ORDER BY id DESC', 0, 1);
        return $db->loadObject();
    }

    private function updateRow($id, array $values)
    {
        $db = JFactory::getDbo();
        $sets = [];
        foreach ($values as $k => $v) {
            $sets[] = $db->quoteName($k) . ' = ' . $db->quote((string) $v);
        }
        $db->setQuery('UPDATE ' . $db->quoteName($this->_tablename) . ' SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $id);
        $db->execute();
    }

    /** One history entry per key (event id), with the order status unchanged. */
    private function noteOnce($row, $key, $text)
    {
        $seen = json_decode((string) $row->arionpay_seen, true) ?: [];
        if (in_array($key, $seen, true)) {
            return;
        }
        $seen[] = $key;
        $this->updateRow($row->id, ['arionpay_seen' => json_encode(array_slice($seen, -100))]);
        $modelOrder = VmModel::getModel('orders');
        $order = $modelOrder->getOrder((int) $row->virtuemart_order_id);
        $modelOrder->updateStatusForOneOrder((int) $row->virtuemart_order_id, ['order_status' => $order['details']['BT']->order_status, 'customer_notified' => 0, 'comments' => $text], true);
    }

    private function firstMethod()
    {
        $db = JFactory::getDbo();
        $db->setQuery('SELECT virtuemart_paymentmethod_id FROM #__virtuemart_paymentmethods WHERE payment_element = ' . $db->quote('arionpay') . ' AND published = 1 ORDER BY virtuemart_paymentmethod_id', 0, 1);
        $id = (int) $db->loadResult();
        return $id ? $this->getVmPluginMethod($id) : null;
    }

    /** Random id of this shop (made once), so two shops on one ArionPay store never touch each other's orders. */
    private function siteId()
    {
        $db = JFactory::getDbo();
        $db->setQuery('SELECT ' . $db->quoteName('params') . ' FROM #__extensions WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('vmpayment') . ' AND element = ' . $db->quote('arionpay'));
        $params = json_decode((string) $db->loadResult(), true) ?: [];
        if (!empty($params['site_id']) && preg_match('/^[a-f0-9]{12}$/', $params['site_id'])) {
            return $params['site_id'];
        }
        $params['site_id'] = bin2hex(random_bytes(6));
        $db->setQuery('UPDATE #__extensions SET ' . $db->quoteName('params') . ' = ' . $db->quote(json_encode($params)) . ' WHERE type = ' . $db->quote('plugin') . ' AND folder = ' . $db->quote('vmpayment') . ' AND element = ' . $db->quote('arionpay'));
        $db->execute();
        return $params['site_id'];
    }

    private function respond($status, array $body)
    {
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body);
        JFactory::getApplication()->close();
    }

    // ------------------------------------------------------------------ standard VirtueMart plumbing

    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id) || !($row = $this->getDataByOrderId($virtuemart_order_id))) {
            return null;
        }
        $html = '<table class="adminlist table">' . $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $row->payment_name);
        $html .= $this->getHtmlRowBE('ArionPay invoice', htmlspecialchars((string) $row->arionpay_invoice_id, ENT_QUOTES, 'UTF-8') . ($row->arionpay_test ? ' (TEST)' : ''));
        $html .= $this->getHtmlRowBE('Amount', htmlspecialchars($row->arionpay_amount . ' ' . $row->payment_currency, ENT_QUOTES, 'UTF-8'));
        $html .= $this->getHtmlRowBE('Paid invoice', htmlspecialchars((string) $row->arionpay_paid_invoice, ENT_QUOTES, 'UTF-8'));
        return $html . '</table>';
    }

    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)) || !$this->selectedThisElement($method->payment_element)) {
            return null;
        }
        self::getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}
