<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2).'/tools/audits/check_license_artifacts.php';

class LicenseArtifactAuditTest extends TestCase
{
    public function testPolicyDocumentationDoesNotCountAsAnImplementation() {
        $source = "<?php\n/** No purchase_codes or envato integration. */\n// purchase_code must never be implemented\n\$safe = 'normal code';";
        $this->assertFalse(LicenseArtifactAudit::containsArtifact($source));
    }
    public function testExecutableReferencesAreStillRejected() {
        foreach (array("<?php \$db->query('CREATE TABLE purchase_codes (id INT)');",
            "<?php \$url = 'https://envato.example/verify';", "<?php function purchaseCode() {}") as $source) {
            $this->assertTrue(LicenseArtifactAudit::containsArtifact($source));
        }
    }
    public function testNormalRuntimeTreePasses() {
        $this->assertSame(array(), LicenseArtifactAudit::scan(dirname(__DIR__, 2)));
    }
}
