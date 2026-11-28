<?php
use PHPUnit\Framework\TestCase;

class PublicIdTest extends TestCase
{
    public static function setUpBeforeClass(): void {
        $root = dirname(__DIR__, 2);
        if (!defined('BASEPATH')) define('BASEPATH', $root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        require_once $root.'/application/helpers/marvy_helper.php';
        require_once $root.'/application/core/MY_Model.php';
        if (!class_exists('PublicIdProbeModel')) eval('class PublicIdProbeModel extends MY_Model { public function generatePublicId() { return $this->new_public_id(); } }');
    }
    public function testHelperIdsFitTheActualChar26ColumnsWithOrWithoutComposer() {
        $ids = array();
        for ($i = 0; $i < 100; $i++) {
            $id = marvy_public_id();
            $this->assertSame(26, strlen($id));
            $this->assertSame(1, preg_match('/^[A-Za-z0-9]{26}$/D', $id));
            $ids[] = $id;
        }
        $this->assertCount(100, array_unique($ids));
    }
    public function testModelIdsFitTheSameSchemaContract() {
        $model = new PublicIdProbeModel();
        $ids = array();
        for ($i = 0; $i < 100; $i++) {
            $id = $model->generatePublicId();
            $this->assertSame(26, strlen($id));
            $this->assertSame(1, preg_match('/^[A-Za-z0-9]{26}$/D', $id));
            $ids[] = $id;
        }
        $this->assertCount(100, array_unique($ids));
    }
    public function testNoUuid36FallbackOrNonexistentUlidNamespaceRemains() {
        $root = dirname(__DIR__, 2);
        foreach (array('application/core/MY_Model.php', 'application/helpers/marvy_helper.php') as $file) {
            $source = file_get_contents($root.'/'.$file);
            $this->assertStringNotContainsString('\\Robbins\\Ulid', $source);
            $this->assertStringNotContainsString('uuid4()', $source);
            $this->assertStringContainsString('\\Ulid\\Ulid::generate()', $source);
        }
    }
}
