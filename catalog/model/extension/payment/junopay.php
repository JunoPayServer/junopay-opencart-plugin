<?php
class ModelExtensionPaymentJunopay extends Model {
    public function getMethod($address, $total) {
        if (!$this->config->get('payment_junopay_status')) {
            return array();
        }

        return array(
            'code'       => 'junopay',
            'title'      => $this->config->get('payment_junopay_title') ?: 'JunoPay',
            'terms'      => '',
            'sort_order' => (int)$this->config->get('payment_junopay_sort_order')
        );
    }
}
