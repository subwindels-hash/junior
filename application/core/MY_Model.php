<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Model extends CI_Model {
    protected $table;
    protected $primary_key = 'id';

    public function find_by_id($id){ return $this->db->where($this->primary_key,$id)->get($this->table)->row(); }
    public function find_by_public_id($pid){ return $this->db->where('public_id',$pid)->get($this->table)->row(); }
    public function find_by($where){ return $this->db->where($where)->get($this->table)->result(); }

    protected function new_public_id(){
        // Public IDs must fit CHAR(26), with or without Composer installed.
        if (class_exists(\Ulid\Ulid::class)) return (string)\Ulid\Ulid::generate();
        return bin2hex(random_bytes(13));
    }

    protected function now_utc(){ return gmdate('Y-m-d H:i:s'); }
}
