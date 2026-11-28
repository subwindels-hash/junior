<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2).'/application/core/Env.php';

class EnvLoadingTest extends TestCase
{
    private function boot($text, array $process, callable $check) {
        $savedEnv = $_ENV; $savedServer = $_SERVER; $savedProcess = getenv();
        $reflection = new ReflectionClass('Env'); $savedProperties = array();
        foreach (array('booted', 'root') as $name) {
            $p = $reflection->getProperty($name); $p->setAccessible(true);
            $savedProperties[$name] = $p->getValue();
            $p->setValue(null, $name === 'booted' ? false : null);
        }
        $root = sys_get_temp_dir().'/env-loading-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        file_put_contents($root.'/.env', $text);
        try {
            foreach (array_keys(getenv()) as $name) putenv($name);
            $_ENV = array(); $_SERVER = array();
            foreach ($process as $name => $value) {
                putenv($name.'='.$value); $_ENV[$name] = $_SERVER[$name] = $value;
            }
            Env::bootstrap($root);
            $check();
        } finally {
            foreach (array_keys(getenv()) as $name) putenv($name);
            foreach ($savedProcess as $name => $value) putenv($name.'='.$value);
            $_ENV = $savedEnv; $_SERVER = $savedServer;
            foreach ($savedProperties as $name => $value) {
                $p = $reflection->getProperty($name); $p->setAccessible(true); $p->setValue(null, $value);
            }
            $this->removeFixture($root);
        }
    }
    private function removeFixture($path) {
        if (!is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') $this->removeFixture($path.'/'.$entry);
        rmdir($path);
    }
    public function testBlankPortableHostingSecretsDoNotSuppressPopulatedDotEnv() {
        $key = str_repeat('k', 64); $auth = str_repeat('a', 64);
        $this->boot('VP_ENCRYPTION_KEY='.$key."\nVP_AUTH_SECRET=".$auth, array('VP_ENCRYPTION_KEY' => '', 'VP_AUTH_SECRET' => ''), function () use ($key, $auth) {
            $this->assertSame($key, Env::get('ENCRYPTION_KEY'));
            $this->assertSame($key, getenv('ENCRYPTION_KEY'));
            $this->assertSame($auth, getenv('APP_KEY'));
        });
    }
    public function testBlankCanonicalHostingSecretsDoNotBlockPortableAliases() {
        $key = str_repeat('k', 64); $auth = str_repeat('a', 64);
        $this->boot('VP_ENCRYPTION_KEY='.$key."\nVP_AUTH_SECRET=".$auth."\nVP_SETUP_TOKEN=".str_repeat('s', 64),
            array('ENCRYPTION_KEY' => '', 'APP_KEY' => '', 'SETUP_TOKEN' => ''), function () use ($key, $auth) {
                $this->assertSame($key, getenv('ENCRYPTION_KEY'));
                $this->assertSame($auth, getenv('APP_KEY'));
                $this->assertSame(str_repeat('s', 64), getenv('SETUP_TOKEN'));
            });
    }
    public function testBlankCanonicalHostingValueCanBeFilledFromCanonicalDotEnv() {
        $this->boot('ENCRYPTION_KEY='.str_repeat('c', 64), array('ENCRYPTION_KEY' => ''), function () {
            $this->assertSame(str_repeat('c', 64), Env::get('ENCRYPTION_KEY'));
        });
    }
    public function testNonemptyServerSecretsAreNeverReplacedByFileValues() {
        $this->boot('VP_ENCRYPTION_KEY='.str_repeat('f', 64)."\nVP_AUTH_SECRET=".str_repeat('g', 64),
            array('VP_ENCRYPTION_KEY' => str_repeat('p', 64), 'VP_AUTH_SECRET' => str_repeat('q', 64)), function () {
                $this->assertSame(str_repeat('p', 64), Env::get('ENCRYPTION_KEY'));
                $this->assertSame(str_repeat('q', 64), getenv('APP_KEY'));
            });
    }
    public function testNonemptyCanonicalServerSecretRetainsItsValue() {
        $this->boot('ENCRYPTION_KEY='.str_repeat('f', 64)."\nAPP_KEY=".str_repeat('g', 64),
            array('ENCRYPTION_KEY' => str_repeat('p', 64), 'APP_KEY' => str_repeat('q', 64)), function () {
                $this->assertSame(str_repeat('p', 64), Env::get('ENCRYPTION_KEY'));
                $this->assertSame(str_repeat('q', 64), getenv('APP_KEY'));
            });
    }
    public function testEmptyDatabasePasswordRetainsItsExistingPrecedence() {
        $this->boot("DB_PASSWORD=file-password\nVP_DB_NAME=file-name", array('DB_PASSWORD' => '', 'DB_NAME' => 'server-name'), function () {
            $this->assertSame('', getenv('DB_PASSWORD'));
            $this->assertSame('server-name', getenv('DB_NAME'));
        });
    }
    public function testUtf8BomDoesNotHideTheFirstEncryptionKey() {
        $this->boot("\xef\xbb\xbfVP_ENCRYPTION_KEY=".str_repeat('b', 64), array(), function () {
            $this->assertSame(str_repeat('b', 64), Env::get('ENCRYPTION_KEY'));
        });
    }
    public function testMissingSecretIsStillRefusedInProductionRatherThanGenerated() {
        if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__, 2).'/system/');
        if (!defined('APPPATH')) define('APPPATH', dirname(__DIR__, 2).'/application/');
        require_once APPPATH.'libraries/EncryptionService.php';
        $this->boot("VP_ENCRYPTION_KEY=\n", array('VP_ENCRYPTION_KEY' => ''), function () {
            $refused = false;
            try { EncryptionService::resolve_key('production'); } catch (RuntimeException $e) { $refused = true; }
            $this->assertTrue($refused);
            $this->assertNull(Env::get('ENCRYPTION_KEY'));
        });
    }
    public function testPopulatedKeyActuallyPassesProductionEncryptionValidation() {
        if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__, 2).'/system/');
        if (!defined('APPPATH')) define('APPPATH', dirname(__DIR__, 2).'/application/');
        require_once APPPATH.'libraries/EncryptionService.php';
        $this->boot('VP_ENCRYPTION_KEY='.str_repeat('z', 64), array('VP_ENCRYPTION_KEY' => ''), function () {
            $this->assertSame(str_repeat('z', 64), EncryptionService::resolve_key('production'));
        });
    }
}
