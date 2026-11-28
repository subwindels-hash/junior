<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Giftcard_sales extends Auth_Controller {
    public function __construct() {
        parent::__construct();
        $this->load->library('GiftcardSellService');
        $this->load->model('Giftcard_sale_model');
        $this->output->set_header('Cache-Control: no-store, private');
    }
    public function index() {
        $catalogue = $this->giftcardsellservice->catalogue();
        $page = max(1, (int)$this->input->get('page'));
        $quote = $this->input->get('quote', true);
        $this->load->view('layouts/app', array(
            'title' => 'Sell gift cards', 'nav_active' => 'dashboard/giftcards',
            'content_view' => 'dashboard/giftcards/sell', 'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(), 'catalogue' => $catalogue,
            'sales' => $this->Giftcard_sale_model->listing($this->current_user->id, $page),
            'total' => $this->Giftcard_sale_model->count_listing($this->current_user->id), 'page' => $page,
            'quote' => $quote ? $this->Giftcard_sale_model->public_row($quote, $this->current_user->id) : null,
        ));
    }
    public function quote() {
        $this->post_only();
        $res = $this->giftcardsellservice->quote($this->current_user, array(
            'giftcard_id' => $this->input->post('giftcard_id', true),
            'amount' => $this->input->post('amount', true)));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/giftcards/sell');
        }
        redirect('dashboard/giftcards/sell?quote='.rawurlencode($res['sale']->public_id));
    }
    public function submit($public_id) {
        $this->post_only();
        if (!$this->Giftcard_sale_model->public_row($public_id, $this->current_user->id)) show_404();
        // Do not HTML-filter a bearer code: mutate neither its characters nor its value.
        $sale = $this->Giftcard_sale_model->public_row($public_id, $this->current_user->id);
        $res = $sale->card_form === 'Physical'
            ? $this->giftcardsellservice->submit_images($this->current_user, $public_id,
                $_FILES['images'] ?? null, $this->input->post('accepted', true))
            : $this->giftcardsellservice->submit($this->current_user, $public_id,
                $this->input->post('code', false), $this->input->post('accepted', true));
        $this->session->set_flashdata(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok'])
            ? 'Sale submitted for review. No wallet credit is issued until the provider confirms completion.' : $res['error']);
        redirect('dashboard/giftcards/sell');
    }
    private function post_only() { if ($this->input->method(true) !== 'POST') show_404(); }
}
