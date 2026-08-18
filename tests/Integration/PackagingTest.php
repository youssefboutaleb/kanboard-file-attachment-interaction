<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The release archive is part of the product: it is what users actually install,
 * and for Kanboard's remote installer it is the ONLY thing they receive.
 *
 * These assertions read the packaging script rather than running it, so they hold
 * in the php:8.1-cli container where zip/rsync are absent. What they protect:
 *
 *   - the archive stayed an allow-list. It was previously an rsync exclude list,
 *     which shipped CLAUDE.md, AGENTS.md, implementation_plan.md and 154 KB of
 *     walkthrough.md to end users because nobody had added them to it.
 *   - the version reported by the script, Plugin.php and CHANGELOG.md agree, so a
 *     tag cannot produce an archive that disagrees with the release notes.
 */
class PackagingTest extends TestCase
{
    private string $script;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = (string) file_get_contents($this->root . '/scripts/package-plugin.sh');
    }

    public function testArchiveIsBuiltFromAnExplicitAllowList(): void
    {
        $this->assertStringContainsString('PAYLOAD=(', $this->script);

        foreach (['Plugin.php', 'src', 'Template', 'Assets', 'LICENSE', 'NOTICE', 'README.md'] as $required) {
            $this->assertStringContainsString('"' . $required . '"', $this->script);
        }
    }

    public function testDevelopmentArtefactsAreRejected(): void
    {
        $this->assertStringContainsString('FORBIDDEN=(', $this->script);

        foreach (['CLAUDE.md', 'AGENTS.md', 'walkthrough.md', 'implementation_plan.md', 'composer.lock'] as $banned) {
            $this->assertStringContainsString('"' . $banned . '"', $this->script);
        }
    }

    /**
     * Kanboard's Installer::update() removes the directory named by statIndex(0)
     * before extracting. A flat archive would make it delete the wrong path.
     */
    public function testArchiveRootEntryIsVerified(): void
    {
        $this->assertStringContainsString('FIRST_ENTRY', $this->script);
        $this->assertStringContainsString('statIndex(0)', $this->script);
    }

    /**
     * src/ must never reference tests/, which the archive excludes: the two
     * controllers used to require_once a stub from tests/stubs/.
     */
    public function testPackagedSourceDoesNotReferenceTestDirectory(): void
    {
        $this->assertStringContainsString('tests/stubs', $this->script);

        foreach (['src/Controller/FilePreviewController.php', 'src/Controller/FileStreamController.php'] as $file) {
            $this->assertStringNotContainsString(
                'tests/stubs',
                (string) file_get_contents($this->root . '/' . $file),
                $file . ' must not reference the test stubs excluded from the release archive'
            );
        }
    }

    public function testPluginVersionMatchesChangelog(): void
    {
        $plugin = (string) file_get_contents($this->root . '/Plugin.php');
        preg_match("/function getPluginVersion.*?return '([^']+)'/s", $plugin, $m);

        $this->assertNotEmpty($m, 'Plugin::getPluginVersion() could not be parsed');
        $version = $m[1];

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $this->assertStringContainsString(
            '[' . $version . ']',
            (string) file_get_contents($this->root . '/CHANGELOG.md'),
            'CHANGELOG.md has no section for the version Plugin.php declares'
        );
    }

    public function testLicenseMetadataIsConsistent(): void
    {
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);

        $license = (string) file_get_contents($this->root . '/LICENSE');

        // composer.json and the LICENSE file drifted apart once before: LICENSE was
        // Apache-2.0 while composer.json and the README both claimed MIT. Nothing
        // caught it, and plugins.json requires a `license` field, so a submission
        // would have declared something untrue.
        $this->assertSame('MIT', $composer['license']);
        $this->assertStringContainsString('MIT License', $license);

        // License boilerplate ships with placeholders; a release must not go out
        // still carrying them.
        $this->assertStringNotContainsString('[name of copyright owner]', $license);
        $this->assertStringNotContainsString('[fullname]', $license);
        $this->assertMatchesRegularExpression('/Copyright \(c\) \d{4}/', $license);
    }

    /**
     * Every bundled third-party bundle must be accounted for in NOTICE.
     */
    public function testBundledVendorJavaScriptIsAttributed(): void
    {
        $notice = (string) file_get_contents($this->root . '/NOTICE');

        foreach (glob($this->root . '/Assets/js/vendor/*.js') ?: [] as $vendorFile) {
            $this->assertStringContainsString(
                basename($vendorFile),
                $notice,
                basename($vendorFile) . ' is redistributed but has no NOTICE entry'
            );
        }
    }
}
