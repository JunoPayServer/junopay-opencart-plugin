<?php
class ControllerExtensionPaymentJunopay extends Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/payment/junopay');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_junopay', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');
        $data['action'] = $this->url->link('extension/payment/junopay', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        foreach (array('status', 'title', 'api_base_url', 'merchant_api_key', 'webhook_secret', 'zatoshis_per_currency_unit', 'order_status_id', 'sort_order') as $key) {
            $settingKey = 'payment_junopay_' . $key;
            $data[$settingKey] = isset($this->request->post[$settingKey]) ? $this->request->post[$settingKey] : $this->config->get($settingKey);
        }
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/payment/junopay', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/junopay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}
