<?php
/** Runtime-only licensing gate. Comments and the tests enforcing this policy
 * are not licensing implementations. Tokenize PHP instead of grepping them.
 */
class LicenseArtifactAudit
{
    public static function containsArtifact($source) {
        $runtime = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $runtime .= $token[1];
            } else $runtime .= $token;
        }
        return (bool)preg_match('/purchase_?code|envato/i', $runtime);
    }
    public static function scan($root) {
        $files = array($root.'/index.php');
        foreach (array('application', 'cron') as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') $files[] = $file->getPathname();
        }
        $bad = array();
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) throw new RuntimeException('Runtime source could not be read.');
            if (self::containsArtifact($source)) $bad[] = substr($file, strlen($root) + 1);
        }
        return $bad;
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $bad = LicenseArtifactAudit::scan(dirname(__DIR__, 2));
    foreach ($bad as $file) fwrite(STDERR, 'Licensing artifact in runtime source: '.$file."\n");
    if ($bad) exit(1);
    echo "No licensing artifacts in runtime PHP.\n";
}
