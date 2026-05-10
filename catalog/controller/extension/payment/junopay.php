<?php
class ControllerExtensionPaymentJunopay extends Controller {
    public function index() {
        $this->load->language('extension/payment/junopay');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_title'] = $this->language->get('text_title');
        $data['text_checkout_intro'] = $this->language->get('text_checkout_intro');
        $data['text_checkout_detail'] = $this->language->get('text_checkout_detail');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['action'] = $this->url->link('extension/payment/junopay/confirm', '', true);

        return $this->load->view('extension/payment/junopay', $data);
    }

    public function confirm() {
        $this->load->language('extension/payment/junopay');

        $json = array();

        if (empty($this->session->data['order_id'])) {
            $json['error'] = $this->language->get('error_order');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        if (!$order) {
            $json['error'] = $this->language->get('error_order');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $amountZat = (int)round((float)$order['total'] * (float)($this->config->get('payment_junopay_zatoshis_per_currency_unit') ?: 100000000));
        $invoice = $this->createInvoice($order, $amountZat);
        if (!empty($invoice['error'])) {
            $json['error'] = $invoice['error'];
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $comment = "JunoPay invoice: " . $invoice['invoice_id'] . "\nAddress: " . $invoice['address'];
        $this->model_checkout_order->addOrderHistory(
            $order['order_id'],
            (int)$this->config->get('payment_junopay_order_status_id'),
            $comment,
            true
        );

        $this->session->data['junopay_invoice'] = $invoice;
        $json['redirect'] = $this->url->link('extension/payment/junopay/invoice', '', true);

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function invoice() {
        $this->load->language('extension/payment/junopay');
        $invoice = isset($this->session->data['junopay_invoice']) ? $this->session->data['junopay_invoice'] : array();

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_amount'] = $this->language->get('text_amount');
        $data['text_address'] = $this->language->get('text_address');
        $data['text_invoice'] = $this->language->get('text_invoice');
        $data['text_copy'] = $this->language->get('text_copy');
        $data['text_copied'] = $this->language->get('text_copied');
        $data['text_waiting'] = $this->language->get('text_waiting');
        $data['text_exact'] = $this->language->get('text_exact');
        $data['text_wallet_hint'] = $this->language->get('text_wallet_hint');
        $data['amount'] = isset($invoice['amount_zat']) ? ((int)$invoice['amount_zat'] / 100000000) . ' JUNO' : '';
        $data['address'] = isset($invoice['address']) ? $invoice['address'] : '';
        $data['invoice_id'] = isset($invoice['invoice_id']) ? $invoice['invoice_id'] : '';
        $data['status_url'] = $this->url->link('extension/payment/junopay/status', '', true);
        $data['continue'] = $this->url->link('common/home', '', true);
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');

        $this->response->setOutput($this->load->view('extension/payment/junopay_invoice', $data));
    }

    public function status() {
        $this->load->language('extension/payment/junopay');

        $invoice = isset($this->session->data['junopay_invoice']) ? $this->session->data['junopay_invoice'] : array();
        if (empty($invoice['invoice_id']) || empty($invoice['invoice_token'])) {
            $this->json(array('ok' => false, 'error' => 'missing_invoice'));
            return;
        }

        $result = $this->getPublicInvoice($invoice['invoice_id'], $invoice['invoice_token']);
        if (!empty($result['error'])) {
            $this->json(array('ok' => false, 'error' => $result['error']));
            return;
        }

        $publicInvoice = isset($result['invoice']) && is_array($result['invoice']) ? $result['invoice'] : $result;
        $this->session->data['junopay_invoice'] = array_merge($invoice, $publicInvoice);
        $phase = $this->invoicePhase($publicInvoice);

        if (!empty($this->session->data['order_id']) && (!isset($invoice['phase']) || $invoice['phase'] !== $phase)) {
            $this->load->model('checkout/order');
            $this->model_checkout_order->addOrderHistory(
                (int)$this->session->data['order_id'],
                (int)$this->config->get('payment_junopay_order_status_id'),
                'JunoPay invoice state: ' . $phase,
                false
            );
            $this->session->data['junopay_invoice']['phase'] = $phase;
        }

        $this->json(array(
            'ok' => true,
            'phase' => $phase,
            'order_status' => $phase,
            'invoice' => $publicInvoice
        ));
    }

    private function createInvoice(array $order, int $amountZat): array {
        $baseUrl = rtrim((string)$this->config->get('payment_junopay_api_base_url'), '/');
        $apiKey = (string)$this->config->get('payment_junopay_merchant_api_key');
        if ($baseUrl === '' || $apiKey === '') {
            return array('error' => 'JunoPay is not configured.');
        }

        $payload = json_encode(array(
            'external_order_id' => 'opencart-order-' . $order['order_id'],
            'amount_zat' => $amountZat,
            'metadata' => array(
                'platform' => 'opencart',
                'order_id' => (string)$order['order_id'],
                'currency' => $order['currency_code'],
                'total' => (string)$order['total']
            )
        ));

        $ch = curl_init($baseUrl . '/v1/invoices');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status > 299) {
            return array('error' => $err ?: 'JunoPay invoice creation failed.');
        }

        $decoded = json_decode($body, true);
        $data = is_array($decoded) && isset($decoded['data']) ? $decoded['data'] : array();
        $invoice = isset($data['invoice']) && is_array($data['invoice']) ? $data['invoice'] : array();
        $invoiceToken = isset($data['invoice_token']) ? (string)$data['invoice_token'] : '';
        $address = isset($invoice['address']) ? $invoice['address'] : (isset($invoice['payment_address']) ? $invoice['payment_address'] : '');
        if (empty($invoice['invoice_id']) || $address === '' || $invoiceToken === '') {
            return array('error' => 'JunoPay returned an incomplete invoice.');
        }

        return array(
            'invoice_id' => $invoice['invoice_id'],
            'invoice_token' => $invoiceToken,
            'address' => $address,
            'amount_zat' => isset($invoice['amount_zat']) ? (int)$invoice['amount_zat'] : $amountZat,
            'received_zat_pending' => isset($invoice['received_zat_pending']) ? (int)$invoice['received_zat_pending'] : 0,
            'received_zat_confirmed' => isset($invoice['received_zat_confirmed']) ? (int)$invoice['received_zat_confirmed'] : 0,
            'expires_at' => isset($invoice['expires_at']) ? (string)$invoice['expires_at'] : ''
        );
    }

    private function getPublicInvoice(string $invoiceId, string $invoiceToken): array {
        $baseUrl = rtrim((string)$this->config->get('payment_junopay_api_base_url'), '/');
        if ($baseUrl === '') {
            return array('error' => 'JunoPay is not configured.');
        }

        $url = $baseUrl . '/v1/public/invoices/' . rawurlencode($invoiceId) . '?token=' . rawurlencode($invoiceToken);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status > 299) {
            return array('error' => $err ?: 'JunoPay status refresh failed.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'ok' || !isset($decoded['data'])) {
            return array('error' => 'JunoPay returned an invalid status response.');
        }

        return $decoded['data'];
    }

    private function invoicePhase(array $invoice): string {
        $amount = isset($invoice['amount_zat']) ? (int)$invoice['amount_zat'] : 0;
        $pending = isset($invoice['received_zat_pending']) ? (int)$invoice['received_zat_pending'] : 0;
        $confirmed = isset($invoice['received_zat_confirmed']) ? (int)$invoice['received_zat_confirmed'] : 0;
        $expiresAt = isset($invoice['expires_at']) ? (string)$invoice['expires_at'] : '';

        if ($expiresAt !== '') {
            $expiry = strtotime($expiresAt);
            if ($expiry !== false && $expiry <= time() && ($pending + $confirmed) < $amount) {
                return 'expired';
            }
        }

        if ($amount > 0 && $confirmed >= $amount) {
            return 'confirmed';
        }

        if ($amount > 0 && ($pending + $confirmed) >= $amount) {
            return 'paid';
        }

        if (($pending + $confirmed) > 0) {
            return 'underpaid';
        }

        return 'awaiting_payment';
    }

    private function json(array $payload): void {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($payload));
    }
}
