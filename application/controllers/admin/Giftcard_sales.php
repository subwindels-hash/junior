<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Giftcard_sales extends Admin_Controller {
    public function __construct() {
        parent::__construct();
        $this->require_perm('giftcards.view');
        $this->load->library('GiftcardSellService');
        $this->load->model('Giftcard_sale_model');
        $this->output->set_header('Cache-Control: no-store, private');
    }
    public function index() {
        $page = max(1, (int)$this->input->get('page'));
        $this->load->view('layouts/app', array(
            'title' => 'Gift-card sales review', 'nav_active' => 'admin/giftcards',
            'content_view' => 'admin/giftcards/sales', 'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'sales' => $this->Giftcard_sale_model->listing(null, $page),
            'total' => $this->Giftcard_sale_model->count_listing(), 'page' => $page,
            'selling_enabled' => $this->giftcardsellservice->enabled(),
        ));
    }
    public function image($public_id, $index) {
        $this->require_perm('giftcards.manage');
        if ($this->input->method(true) !== 'GET') show_404();
        $sale = $this->Giftcard_sale_model->public_row($public_id);
        if (!$sale) show_404();
        $image = $this->giftcardsellservice->image($sale->id, (int)$index, $this->current_user->id);
        if (!$image) show_404();
        $this->output->set_header('X-Content-Type-Options: nosniff')
            ->set_header("Content-Security-Policy: default-src 'none'; sandbox")
            ->set_header('Referrer-Policy: no-referrer')->set_header('Content-Disposition: inline; filename="card-image"')
            ->set_content_type($image['mime'])->set_output($image['bytes']);
    }
    public function dispatch($public_id) {
        $sale = $this->guard($public_id, 'giftcards.manage');
        $this->result($this->giftcardsellservice->dispatch($sale->id, $this->current_user->id),
            'Submitted to Prestmit. An uncertain response stays pending for reconciliation; do not resend the code.');
    }
    public function reject($public_id) {
        $sale = $this->guard($public_id, 'giftcards.manage');
        $this->result($this->giftcardsellservice->reject($sale->id, $this->current_user->id), 'Unsubmitted sale declined.');
    }
    public function reconcile($public_id) {
        // This action may credit a wallet: STAFF's manage permission alone is not enough.
        $sale = $this->guard($public_id, 'giftcards.refund');
        $this->result($this->giftcardsellservice->reconcile($sale->id, $this->current_user->id),
            'Provider status checked. Wallet credits require verified completion; unknown trades remain pending.');
    }
    private function guard($public_id, $permission) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($permission);
        $sale = $this->Giftcard_sale_model->public_row($public_id);
        if (!$sale) show_404();
        return $sale;
    }
    private function result($res, $success) {
        $this->session->set_flashdata(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? $success : $res['error']);
        redirect('admin/giftcards/sales');
    }
}
