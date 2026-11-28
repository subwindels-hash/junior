<?php
/**
 * Temporary, read-only cPanel environment diagnostic.
 * Upload beside index.php, open /env-check.php, then DELETE this file.
 * No secret values, key lengths, absolute paths, database probes or writes.
 * Not part of the normal deployment archive.
 */
class PanelEnvCheck
{
    public static function inspect($root)
    {
        $root = rtrim($root, '/\\');
        $report = array(
            'env_file' => 'missing',
            'env_txt_exists' => is_file($root.'/.env.txt'),
            'env_example_exists' => is_file($root.'/.env.example'),
            'loader_available' => is_file($root.'/application/core/Env.php'),
            'utf8_bom' => false,
            'unsupported_encoding' => false,
            'escaped_setting_names' => false,
            'duplicate_encryption_setting' => false,
            'file_encryption_key_present' => false,
            'effective_encryption_key_present' => false,
            'effective_encryption_key_minimum_length' => false,
            'process_encryption_setting_present' => false,
            'empty_process_setting_blocks_file' => false,
            'empty_secret_fallback_supported' => false,
            'conflicting_encryption_aliases' => false,
            'next_steps' => array(),
        );
        $parsed = array();
        $path = $root.'/.env';
        if (is_file($path)) {
            $report['env_file'] = 'unreadable';
            if (is_readable($path)) {
                // Bound memory even if the operator accidentally renamed a backup.
                $contents = @file_get_contents($path, false, null, 0, 1048577);
                if ($contents === false) $report['env_file'] = 'read_failed';
                elseif (strlen($contents) > 1048576) $report['env_file'] = 'too_large';
                else {
                    $report['env_file'] = 'readable';
                    $report['utf8_bom'] = substr($contents, 0, 3) === "\xef\xbb\xbf";
                    $report['unsupported_encoding'] = strpos($contents, "\0") !== false
                        || substr($contents, 0, 2) === "\xff\xfe" || substr($contents, 0, 2) === "\xfe\xff";
                    $report['escaped_setting_names'] = (bool)preg_match('/^\s*(?:export\s+)?[A-Za-z][A-Za-z0-9_\\\\]*\\\\_[A-Za-z0-9_\\\\]*\s*=/m', $contents);
                    foreach (array('VP_ENCRYPTION_KEY', 'ENCRYPTION_KEY') as $name) {
                        if (preg_match_all('/^\s*(?:export\s+)?'.preg_quote($name, '/').'\s*=/m', $contents) > 1) {
                            $report['duplicate_encryption_setting'] = true;
                        }
                    }
                    if ($report['loader_available'] && !$report['unsupported_encoding']) {
                        if (!class_exists('Env', false)) require_once $root.'/application/core/Env.php';
                        // Use the installed parser, but NEVER bootstrap: bootstrap
                        // changes the process environment and creates directories.
                        $parsed = Env::parse($contents);
                    }
                }
            }
        }
        if ($report['loader_available']) {
            if (!class_exists('Env', false)) require_once $root.'/application/core/Env.php';
            $fills_empty = defined('Env::FILLS_EMPTY_BOOTSTRAP_SECRETS') && Env::FILLS_EMPTY_BOOTSTRAP_SECRETS;
            $report['empty_secret_fallback_supported'] = $fills_empty;
            $names = array('VP_ENCRYPTION_KEY', 'ENCRYPTION_KEY');
            $effective = array();
            foreach ($names as $name) {
                $process = Env::raw($name);
                $report['process_encryption_setting_present'] = $report['process_encryption_setting_present'] || $process !== null;
                // Mirror the installed loader, including its empty-secret fix.
                $fill = $fills_empty && $process === '' && ($parsed[$name] ?? '') !== '';
                $effective[$name] = $process !== null && !$fill ? $process : ($parsed[$name] ?? null);
                if ($process === '' && trim((string)($parsed[$name] ?? '')) !== '') {
                    $report['empty_process_setting_blocks_file'] = !$fills_empty;
                }
            }
            $file_key = self::portableFirst($parsed);
            $key = self::portableFirst($effective);
            $report['file_encryption_key_present'] = $file_key !== '';
            $report['effective_encryption_key_present'] = $key !== '';
            $report['effective_encryption_key_minimum_length'] = strlen($key) >= 32;
            $vp = trim((string)($effective['VP_ENCRYPTION_KEY'] ?? ''));
            $plain = trim((string)($effective['ENCRYPTION_KEY'] ?? ''));
            $report['conflicting_encryption_aliases'] = $vp !== '' && $plain !== '' && !hash_equals($vp, $plain);
        }
        $steps =& $report['next_steps'];
        if ($report['env_file'] === 'missing') $steps[] = 'The .env file is missing from this folder. Check the domain document root and enable Show Hidden Files in cPanel. The filename must be exactly .env, not .env.txt or .env.example.';
        elseif ($report['env_file'] !== 'readable') $steps[] = 'PHP could not safely read .env. Check file ownership, hosting permissions and encoding; do not make secrets world-writable.';
        if (!$report['loader_available']) $steps[] = 'The application environment loader is missing here. Upload this diagnostic beside the panel index.php and verify the application upload is complete.';
        if ($report['utf8_bom'] || $report['unsupported_encoding']) $steps[] = 'Save .env as UTF-8 without BOM, not UTF-16, then retry.';
        if ($report['escaped_setting_names']) $steps[] = 'Remove backslashes from setting names: use VP_ENCRYPTION_KEY, not VP\\_ENCRYPTION\\_KEY. Do not copy Markdown code fences.';
        if ($report['duplicate_encryption_setting']) $steps[] = 'Remove duplicate definitions of the same encryption setting. A later empty definition can replace the value you entered.';
        if ($report['empty_process_setting_blocks_file']) $steps[] = 'An empty hosting process variable blocks a populated .env setting. Ask the host to remove that empty variable from the PHP environment, then restart PHP if required. Do not change the encryption key.';
        if ($report['conflicting_encryption_aliases']) $steps[] = 'VP_ENCRYPTION_KEY and ENCRYPTION_KEY disagree. Retain the correct existing key and remove the conflicting alias; do not generate another key.';
        if ($report['loader_available'] && !$report['effective_encryption_key_present']) $steps[] = 'No non-empty encryption key resolves using the installed loader policy. Check the file, spelling and process overrides above. For an existing database restore its original key.';
        elseif ($report['effective_encryption_key_present'] && !$report['effective_encryption_key_minimum_length']) $steps[] = 'The resolved encryption key is shorter than the required 32 characters. Restore the full original key; do not truncate or replace an existing key.';
        elseif ($report['effective_encryption_key_minimum_length']) $steps[] = 'A non-empty encryption key of sufficient length resolves here. If the panel still says not set, compare the domain document root, PHP handler and deployed application files. This is not a live database, key-strength or full application health check.';
        $steps[] = 'Delete env-check.php after troubleshooting. Do not share your .env contents or secrets.';
        return $report;
    }

    private static function portableFirst(array $values)
    {
        // Env::get checks VP_ first, falling back only for an exactly empty value.
        foreach (array('VP_ENCRYPTION_KEY', 'ENCRYPTION_KEY') as $name) {
            if (isset($values[$name]) && $values[$name] !== '') return trim((string)$values[$name]);
        }
        return '';
    }
}

// Including this file in tests never executes the HTTP/CLI entry point.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
        header('Referrer-Policy: no-referrer');
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            exit;
        }
    }
    try {
        $report = PanelEnvCheck::inspect(__DIR__);
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        }
    } catch (Throwable $e) {
        // Never echo exception text: it could include server paths or file data.
        if (PHP_SAPI !== 'cli') http_response_code(500);
        echo '{"error":"Diagnostic could not complete. Check the upload and ask your host to inspect the PHP error log privately."}';
        exit(1);
    }
}
