<?php

namespace Opencart\Catalog\Controller\Extension\Coingate\Payment;

use CoinGate\Client;
use CoinGate\Merchant\Order;
use Opencart\System\Engine\Controller;

require_once DIR_EXTENSION . 'coingate/system/library/coingate/coingate-php/init.php';

/**
 * @property object $load
 * @property object $language
 * @property object $url
 * @property object $model_checkout_order
 * @property object $config
 * @property object $currency
 * @property object $session
 * @property object $cart
 * @property object $model_extension_coingate_payment_coingate
 * @property object $response
 * @property object $log
 * @property object $request
 */
class Coingate extends Controller
{
    public function index()
    {
        $this->load->language('extension/coingate/payment/coingate');
        $this->load->model('checkout/order');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['action'] = $this->url->link('extension/coingate/payment/coingate|checkout', '', true);

        return $this->load->view('extension/coingate/payment/coingate', $data);
    }

    public function checkout()
    {
        $client = $this->getCoingateClient();

        $this->load->model('checkout/order');
        $this->load->model('extension/coingate/payment/coingate');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $token = md5(uniqid(rand(), true));
        $description = [];

        foreach ($this->cart->getProducts() as $product) {
            $description[] = $product['quantity'] . ' × ' . $product['name'];
        }

        $params = [
            'order_id' => $order_info['order_id'],
            'price_amount' => number_format($order_info['total'] * $this->currency->getvalue($order_info['currency_code']), 8, '.', ''),
            'price_currency' => $order_info['currency_code'],
            'receive_currency' => $this->config->get('payment_coingate_receive_currency'),
            'cancel_url' => $this->url->link('extension/coingate/payment/coingate|cancel', '', true),
            'callback_url' => $this->url->link('extension/coingate/payment/coingate|callback', ['cg_token' => $token], true),
            'success_url' => $this->url->link('extension/coingate/payment/coingate|success', ['cg_token' => $token], true),
            'title' => $this->config->get('config_meta_title') . ' Order #' . $order_info['order_id'],
            'description' => join(', ', $description),
            'token' => $token
        ];

        $cg_order = $client->order->create($params);

        if ($cg_order) {
            $order_data = [
                'order_id' => $order_info['order_id'],
                'token' => $token,
                'cg_invoice_id' => $cg_order->id
            ];

            $this->model_extension_coingate_payment_coingate->addOrder($order_data);

            $this->model_checkout_order->addHistory($order_info['order_id'], $this->config->get('payment_coingate_order_status_id'));

            $this->response->redirect($cg_order->payment_url);
        } else {
            $this->log->write("Order #" . $order_info['order_id'] . " is not valid. Please check CoinGate API request logs.");
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    public function success()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/coingate/payment/coingate');

        $order = $this->model_extension_coingate_payment_coingate->getOrderByToken($this->request->get['cg_token']);

        if (empty($order) || strcmp($order['token'], $this->request->get['cg_token']) !== 0) {
            $this->response->redirect($this->url->link('common/home', '', true));
        } else {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        }
    }

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coingate');

        $order_id = $this->request->post['order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $ext_order = $this->model_extension_coingate_payment_coingate->getOrder($order_id);


        if (!empty($order_info) && !empty($ext_order) && strcmp($ext_order['token'], $this->request->get['cg_token']) === 0) {
            $client = $this->getCoingateClient();

            $cg_order = $client->order->get($ext_order['cg_invoice_id']);

            if ($cg_order) {
                $cg_order_status = match ($cg_order->status) {
                    'paid' => 'payment_coingate_paid_status_id',
                    'confirming' => 'payment_coingate_confirming_status_id',
                    'invalid' => 'payment_coingate_invalid_status_id',
                    'expired' => 'payment_coingate_expired_status_id',
                    'canceled' => 'payment_coingate_canceled_status_id',
                    'refunded' => 'payment_coingate_refunded_status_id',
                    default => NULL,
                };

                if (!is_null($cg_order_status)) {
                    $this->model_checkout_order->addOrderHistory($order_id, $this->config->get($cg_order_status));
                }
            }
        }

        $this->response->addHeader('HTTP/1.1 200 OK');
    }

    private function getCoingateClient(): Client
    {
        return new Client(
            $this->config->get('payment_coingate_api_auth_token'),
            $this->config->get('payment_coingate_test_mode') == 1
        );
    }
}