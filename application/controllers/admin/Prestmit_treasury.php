<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Sensitive partner-account inspection: no create/update/transfer actions. */
class Prestmit_treasury extends Admin_Controller {
    public function __construct() {
        parent::__construct();
        $this->require_perm('providers.view');
        $this->require_perm('providers.manage');
        $this->load->library('PrestmitTreasuryService');
        $this->output->set_header('Cache-Control: no-store, private')
            ->set_header('Referrer-Policy: no-referrer')->set_header('X-Robots-Tag: noindex, nofollow');
    }
    public function index($public_id) {
        if ($this->input->method(true) !== 'GET') {
            $this->output->set_header('Allow: GET')->set_status_header(405)->set_output('Method not allowed');
            return;
        }
        $bank_page = $this->input->get('bank_page', false) ?? '1';
        $history_page = $this->input->get('history_page', false) ?? '1';
        $inspection = $this->prestmittreasuryservice->inspect($public_id, $this->current_user->id, $bank_page, $history_page);
        $this->load->view('layouts/app', array('title' => 'Prestmit treasury (read only)',
            'nav_active' => 'admin/providers', 'content_view' => 'admin/providers/prestmit_treasury',
            'current_user' => $this->current_user, 'permissions' => $this->auth->permissions(),
            'inspection' => $inspection));
    }
}
