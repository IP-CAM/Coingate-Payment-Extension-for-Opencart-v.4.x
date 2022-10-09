<?php

namespace Opencart\Admin\Controller\Extension\Coingate\Payment;

use CoinGate\Client;
use Opencart\System\Engine\Controller;

require_once DIR_EXTENSION . 'coingate/system/library/coingate/coingate-php/init.php';

/**
 * @property object $load
 * @property object $document
 * @property object $language
 * @property object $model_localisation_order_status
 * @property object $session
 * @property object $model_localisation_geo_zone
 * @property object $url
 * @property object $request
 * @property object $model_setting_setting
 * @property object $response
 * @property object $config
 * @property object $user
 * @property object $model_extension_coingate_payment_coingate
 */
class Coingate extends Controller
{
    private array $error = [];

    public function index()
    {
        $this->load->language('extension/coingate/payment/coingate');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/geo_zone');

        $this->handlePostRequest();

        $data['action'] = $this->url->link('extension/coingate/payment/coingate', 'user_token=' . $this->session->data['user_token']);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        $data['receive_currencies'] = $this->getReceiveCurrencies();

        $data['error_warning'] = $this->error['warning'] ?? '';

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/coingate', 'user_token=' . $this->session->data['user_token'], true)
        );

        $fields = $this->getFields();

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        if (strlen($data['payment_coingate_prefill_coingate_invoice_email']) === 0) {
            $data['payment_coingate_prefill_coingate_invoice_email'] = 1;
        }

        $data['payment_coingate_sort_order'] = $this->request->post['payment_coingate_sort_order'] ?? $this->config->get('payment_coingate_sort_order');

        if (empty($data['payment_coingate_api_auth_token']) && !empty($data['payment_coingate_api_secret']))
            $data['payment_coingate_api_auth_token'] = $data['payment_coingate_api_secret'];

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/coingate/payment/coingate', $data));
    }

    private function handlePostRequest()
    {
        if (($this->request->server['REQUEST_METHOD'] != 'POST') || !$this->validate()) {
            return;
        }

        $this->model_setting_setting->editSetting('payment_coingate', $this->request->post);
        $this->session->data['success'] = $this->language->get('text_success');
        $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
    }

    private function validate(): bool
    {
        if (
            !$this->user->hasPermission('modify', 'extension/coingate/payment/coingate') &&
            !$this->user->hasPermission('modify', 'extension/payment/coingate')
        ) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!class_exists('CoinGate\Client')) {
            $this->error['warning'] = $this->language->get('error_composer');
        }

        if (!$this->error) {
            try {
                $result = Client::testConnection(
                    $this->request->post['payment_coingate_api_auth_token'],
                    $this->request->post['payment_coingate_test_mode'] == 1
                );

                if (!$result) {
                    $this->error['warning'] = $this->language->get('error_connection');
                }
            } catch (\Exception $e) {
                $this->error['warning'] = $this->language->get('error_connection');
            }
        }

        return !$this->error;
    }

    /**
     * @return string[]
     */
    public function getFields(): array
    {
        return [
            'payment_coingate_status',
            'payment_coingate_api_auth_token',
            'payment_coingate_api_secret',
            'payment_coingate_order_status_id',
            'payment_coingate_pending_status_id',
            'payment_coingate_confirming_status_id',
            'payment_coingate_paid_status_id',
            'payment_coingate_invalid_status_id',
            'payment_coingate_expired_status_id',
            'payment_coingate_canceled_status_id',
            'payment_coingate_refunded_status_id',
            'payment_coingate_total',
            'payment_coingate_geo_zone_id',
            'payment_coingate_receive_currency',
            'payment_coingate_test_mode',
            'payment_coingate_prefill_coingate_invoice_email'
        ];
    }

    /**
     * @return string[]
     */
    public function getReceiveCurrencies(): array
    {
        $client = new Client();
        $currencies = $client->getMerchantPayoutCurrencies();

        $currencies = array_map(function ($currency) {
            return $currency['symbol'];
        }, (array)$currencies);

        return array_merge($currencies, ['DO_NOT_CONVERT']);
    }

    public function install()
    {
        $this->load->model('extension/coingate/payment/coingate');

        $this->model_extension_coingate_payment_coingate->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/coingate/payment/coingate');

        $this->model_extension_coingate_payment_coingate->uninstall();
    }
}
