<?php
class ControllerExtensionPaymentJunopay extends Controller {
    public function index() {
        $this->load->language('extension/payment/junopay');

        $data['button_confirm'] = $this->language->get('button_confirm');
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
        $data['amount'] = isset($invoice['amount_zat']) ? ((int)$invoice['amount_zat'] / 100000000) . ' JUNO' : '';
        $data['address'] = isset($invoice['address']) ? $invoice['address'] : '';
        $data['invoice_id'] = isset($invoice['invoice_id']) ? $invoice['invoice_id'] : '';
        $data['continue'] = $this->url->link('common/home', '', true);
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');

        $this->response->setOutput($this->load->view('extension/payment/junopay_invoice', $data));
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
        $address = isset($invoice['address']) ? $invoice['address'] : (isset($invoice['payment_address']) ? $invoice['payment_address'] : '');
        if (empty($invoice['invoice_id']) || $address === '') {
            return array('error' => 'JunoPay returned an incomplete invoice.');
        }

        return array(
            'invoice_id' => $invoice['invoice_id'],
            'address' => $address,
            'amount_zat' => isset($invoice['amount_zat']) ? (int)$invoice['amount_zat'] : $amountZat
        );
    }
}
