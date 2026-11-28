<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2).'/env-check.php';
require_once dirname(__DIR__, 2).'/application/core/Env.php';

class EnvCheckTest extends TestCase
{
    private $root;
    private $saved;
    protected function setUp(): void {
        $this->root = sys_get_temp_dir().'/panel-env-check-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/application/core', 0700, true);
        copy(dirname(__DIR__, 2).'/application/core/Env.php', $this->root.'/application/core/Env.php');
        $this->saved = array();
        foreach (array('VP_ENCRYPTION_KEY', 'ENCRYPTION_KEY') as $key) {
            $this->saved[$key] = array(getenv($key), array_key_exists($key, $_ENV), $_ENV[$key] ?? null,
                array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null);
            putenv($key); unset($_ENV[$key], $_SERVER[$key]);
        }
    }
    protected function tearDown(): void {
        foreach ($this->saved as $key => $v) {
            if ($v[0] === false) putenv($key); else putenv($key.'='.$v[0]);
            if ($v[1]) $_ENV[$key] = $v[2]; else unset($_ENV[$key]);
            if ($v[3]) $_SERVER[$key] = $v[4]; else unset($_SERVER[$key]);
        }
        foreach (array('.env', '.env.txt', '.env.example', 'application/core/Env.php') as $file) @unlink($this->root.'/'.$file);
        rmdir($this->root.'/application/core'); rmdir($this->root.'/application'); rmdir($this->root);
    }
    private function inspect($text = null) {
        if ($text !== null) file_put_contents($this->root.'/.env', $text);
        return PanelEnvCheck::inspect($this->root);
    }
    public function testMissingFileIsActionableWithoutPrintingTheServerPath() {
        $r = $this->inspect();
        $this->assertSame('missing', $r['env_file']);
        $this->assertFalse($r['effective_encryption_key_present']);
        $this->assertStringContainsString('domain document root', implode(' ', $r['next_steps']));
        $this->assertStringNotContainsString($this->root, json_encode($r));
    }
    public function testValidPortableKeyResolvesWithoutBootstrappingOrPrintingSecrets() {
        $secret = str_repeat('private-key-', 6);
        $text = 'VP_ENCRYPTION_KEY='.$secret."\nVP_DB_PASS=private-database-password\nVP_SETUP_TOKEN=private-setup-token\n";
        $r = $this->inspect($text);
        $this->assertSame('readable', $r['env_file']);
        $this->assertTrue($r['file_encryption_key_present']);
        $this->assertTrue($r['effective_encryption_key_present']);
        $this->assertTrue($r['effective_encryption_key_minimum_length']);
        foreach (array($secret, 'private-database-password', 'private-setup-token', $this->root) as $value) {
            $this->assertStringNotContainsString($value, json_encode($r));
        }
        $this->assertFalse(is_dir($this->root.'/storage'));
        $this->assertNull(Env::raw('VP_ENCRYPTION_KEY'));
        $this->assertNull(Env::raw('ENCRYPTION_KEY'));
        $this->assertSame($text, file_get_contents($this->root.'/.env'));
    }
    public function testFixedLoaderCanResolveAFileKeyDespiteEmptyProcessValue() {
        putenv('VP_ENCRYPTION_KEY=');
        $r = $this->inspect('VP_ENCRYPTION_KEY='.str_repeat('f', 64));
        $this->assertTrue($r['file_encryption_key_present']);
        $this->assertTrue($r['process_encryption_setting_present']);
        $this->assertFalse($r['empty_process_setting_blocks_file']);
        $this->assertTrue($r['empty_secret_fallback_supported']);
        $this->assertTrue($r['effective_encryption_key_present']);
        $this->assertSame('', getenv('VP_ENCRYPTION_KEY'));
        $this->assertTrue($r['effective_encryption_key_minimum_length']);
    }
    public function testNonemptyProcessValueRemainsTheSourceInsteadOfFileValue() {
        putenv('VP_ENCRYPTION_KEY=short-process-secret');
        $r = $this->inspect('VP_ENCRYPTION_KEY='.str_repeat('f', 64));
        $this->assertTrue($r['effective_encryption_key_present']);
        $this->assertFalse($r['effective_encryption_key_minimum_length']);
        $this->assertFalse($r['empty_process_setting_blocks_file']);
        $this->assertStringNotContainsString('short-process-secret', json_encode($r));
    }
    public function testDuplicateBlankDefinitionExplainsWhyTheEarlierKeyDisappears() {
        $r = $this->inspect('VP_ENCRYPTION_KEY='.str_repeat('f', 64)."\nVP_ENCRYPTION_KEY=\n");
        $this->assertTrue($r['duplicate_encryption_setting']);
        $this->assertFalse($r['file_encryption_key_present']);
        $this->assertStringContainsString('later empty definition', implode(' ', $r['next_steps']));
    }
    public function testMarkdownEscapesDoNotCountAsAConfiguredKey() {
        $r = $this->inspect('VP\\_ENCRYPTION\\_KEY='.str_repeat('f', 64));
        $this->assertTrue($r['escaped_setting_names']);
        $this->assertFalse($r['effective_encryption_key_present']);
    }
    public function testRenamedExampleAndTextFileAreNotLoaded() {
        file_put_contents($this->root.'/.env.txt', 'VP_ENCRYPTION_KEY='.str_repeat('f', 64));
        file_put_contents($this->root.'/.env.example', 'VP_ENCRYPTION_KEY='.str_repeat('f', 64));
        $r = $this->inspect();
        $this->assertTrue($r['env_txt_exists']);
        $this->assertTrue($r['env_example_exists']);
        $this->assertSame('missing', $r['env_file']);
        $this->assertFalse($r['effective_encryption_key_present']);
    }
    public function testUtf8BomAndUtf16HaveExplicitEncodingGuidance() {
        $r = $this->inspect("\xef\xbb\xbfVP_ENCRYPTION_KEY=".str_repeat('f', 64));
        $this->assertTrue($r['utf8_bom']);
        $this->assertTrue($r['effective_encryption_key_present']);
        $this->assertStringContainsString('UTF-8 without BOM', implode(' ', $r['next_steps']));
        $r = $this->inspect("\xff\xfeV\0P\0_\0");
        $this->assertTrue($r['unsupported_encoding']);
        $this->assertFalse($r['effective_encryption_key_present']);
    }
    public function testCanonicalKeyWorksAndConflictingAliasesAreFlagged() {
        $r = $this->inspect('ENCRYPTION_KEY='.str_repeat('c', 64));
        $this->assertTrue($r['effective_encryption_key_present']);
        $this->assertTrue($r['effective_encryption_key_minimum_length']);
        $r = $this->inspect('ENCRYPTION_KEY='.str_repeat('c', 64)."\nVP_ENCRYPTION_KEY=".str_repeat('p', 64));
        $this->assertTrue($r['conflicting_encryption_aliases']);
        $this->assertStringNotContainsString(str_repeat('c', 64), json_encode($r));
        $this->assertStringNotContainsString(str_repeat('p', 64), json_encode($r));
    }
    public function testOversizedFileIsNotParsed() {
        $r = $this->inspect(str_repeat('x', 1048577));
        $this->assertSame('too_large', $r['env_file']);
        $this->assertFalse($r['effective_encryption_key_present']);
    }
    public function testMissingApplicationLoaderIsReportedWithoutTryingToBoot() {
        unlink($this->root.'/application/core/Env.php');
        $r = $this->inspect('VP_ENCRYPTION_KEY='.str_repeat('f', 64));
        $this->assertFalse($r['loader_available']);
        $this->assertStringContainsString('application upload is complete', implode(' ', $r['next_steps']));
    }
}
