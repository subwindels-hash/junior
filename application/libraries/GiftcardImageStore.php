<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Private encrypted image store. No original filenames, public URLs or plaintext files. */
class GiftcardImageStore {
    const MAX_FILE = 5242880;
    const MAX_TOTAL = 20971520;
    private $ci;
    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->library('EncryptionService');
    }
    private function dir() {
        require_once APPPATH.'core/Env.php';
        $paths = Env::writable_paths();
        return rtrim($paths['storage'], '/\\').'/giftcard_images';
    }
    /** Accept PHP's upload array only; callers cannot supply arbitrary local paths. */
    public function ingest($uploads) {
        if (!is_array($uploads) || !is_array($uploads['tmp_name'] ?? null)) throw new RuntimeException('Choose card images.');
        $count = count($uploads['tmp_name']);
        if ($count < 1 || $count > 20) throw new RuntimeException('Choose between 1 and 20 images.');
        $manifest = array(); $total = 0;
        try {
            foreach ($uploads['tmp_name'] as $i => $tmp) {
                if (($uploads['error'][$i] ?? -1) !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('An image did not upload successfully.');
                }
                $size = filesize($tmp);
                if ($size === false || $size < 1 || $size > self::MAX_FILE) throw new RuntimeException('Each image must be at most 5 MiB.');
                $total += $size;
                if ($total > self::MAX_TOTAL) throw new RuntimeException('Images must total at most 20 MiB.');
                $bytes = file_get_contents($tmp);
                $manifest[] = $this->store($bytes);
            }
            return $manifest;
        } catch (Throwable $e) { $this->delete($manifest); throw $e; }
    }
    /** Internal byte boundary, also exercised without HTTP by the regression suite. */
    public function store($bytes) {
        if (!is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > self::MAX_FILE) throw new RuntimeException('Invalid image size.');
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, array('image/jpeg', 'image/png'), true)
            || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40000000) {
            throw new RuntimeException('Only valid JPEG or PNG images up to 40 megapixels are accepted.');
        }
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Image storage unavailable.');
        $guard = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
        if (!is_file($dir.'/.htaccess') && file_put_contents($dir.'/.htaccess', $guard) === false) throw new RuntimeException('Image storage unavailable.');
        $key = bin2hex(random_bytes(32));
        $encrypted = $this->ci->encryptionservice->encrypt($bytes);
        if (file_put_contents($dir.'/'.$key, $encrypted, LOCK_EX) !== strlen($encrypted)) {
            @unlink($dir.'/'.$key);
            throw new RuntimeException('Could not store card image.');
        }
        @chmod($dir.'/'.$key, 0600);
        return array('key' => $key, 'mime' => $mime, 'sha256' => hash('sha256', $bytes));
    }
    public function read(array $item) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $item['key'] ?? '') || !in_array($item['mime'] ?? '', array('image/jpeg', 'image/png'), true)) {
            throw new RuntimeException('Invalid image manifest.');
        }
        $path = $this->dir().'/'.$item['key'];
        if (is_link($path) || !is_file($path) || filesize($path) > 7000000) throw new RuntimeException('Image unavailable.');
        $bytes = $this->ci->encryptionservice->open(file_get_contents($path));
        if ($bytes === null || !hash_equals((string)($item['sha256'] ?? ''), hash('sha256', $bytes))) throw new RuntimeException('Image integrity check failed.');
        return array('bytes' => $bytes, 'mime' => $item['mime']);
    }
    public function delete(array $manifest) {
        foreach ($manifest as $item) {
            if (is_array($item) && preg_match('/^[a-f0-9]{64}$/D', $item['key'] ?? '')) {
                $path = $this->dir().'/'.$item['key'];
                if (is_file($path) && !unlink($path)) throw new RuntimeException('Image deletion failed.');
            }
        }
    }
}
