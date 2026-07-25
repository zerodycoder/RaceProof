<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\ReleaseTools\Release;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

require_once dirname(__DIR__, 2).'/tools/release/Release.php';

final class ReleaseEngineeringTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function constraints(): iterable
    {
        yield 'stable v1' => ['1.0.0', '^1.0'];
        yield 'stable minor' => ['1.4.2', '^1.4'];
        yield 'beta' => ['1.0.0-beta.3', '1.0.0-beta.3@beta'];
        yield 'release candidate' => ['2.1.0-rc.1', '2.1.0-rc.1@RC'];
    }

    #[DataProvider('constraints')]
    public function test_runtime_constraint_is_derived_from_one_release_version(string $version, string $expected): void
    {
        self::assertSame($expected, Release::runtimeConstraint($version));
    }

    public function test_invalid_release_version_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid release version');

        Release::version('v1.0.0');
    }

    public function test_release_archives_are_reproducible_aligned_and_installable_shapes(): void
    {
        $root = dirname(__DIR__, 2);
        $first = Release::buildArtifacts($root, '1.0.0-beta.1', $root.'/build/release/tests/first');
        $second = Release::buildArtifacts($root, '1.0.0-beta.1', $root.'/build/release/tests/second');

        self::assertSame($first['laravel']['sha256'], $second['laravel']['sha256']);
        self::assertSame($first['runtime']['sha256'], $second['runtime']['sha256']);

        $laravel = $this->archiveManifest($first['laravel']['path']);
        $runtime = $this->archiveManifest($first['runtime']['path']);

        self::assertSame('raceproof/laravel', $laravel['name']);
        self::assertSame('raceproof/runtime', $runtime['name']);
        self::assertSame('1.0.0-beta.1', $laravel['version']);
        self::assertSame('1.0.0-beta.1', $runtime['version']);
        self::assertIsArray($laravel['require']);
        self::assertIsArray($runtime['require']);
        self::assertSame('1.0.0-beta.1@beta', $laravel['require']['raceproof/runtime'] ?? null);
        self::assertArrayNotHasKey('repositories', $laravel);
        self::assertArrayNotHasKey('require-dev', $laravel);
        self::assertArrayNotHasKey('autoload-dev', $laravel);
        self::assertArrayNotHasKey('scripts', $laravel);
        self::assertSame(['php' => '^8.2'], $runtime['require']);

        $laravelZip = new ZipArchive;
        self::assertTrue($laravelZip->open($first['laravel']['path']));

        try {
            self::assertNotFalse($laravelZip->locateName('LICENSE'));
            self::assertNotFalse($laravelZip->locateName('docs/public-api.md'));
            self::assertNotFalse($laravelZip->locateName('docs/release-audit.md'));
            self::assertNotFalse($laravelZip->locateName('docs/templates/private-beta-invitation.md'));
            self::assertNotFalse($laravelZip->locateName('resources/views/studio.php'));
            self::assertNotFalse($laravelZip->locateName('stubs/race-test.phpunit.php.stub'));
            self::assertNotFalse($laravelZip->locateName('api/public-api.json'));
            self::assertNotFalse($laravelZip->locateName('audit/release-audit.json'));
            self::assertNotFalse($laravelZip->locateName('beta/evidence.schema.json'));
            self::assertNotFalse($laravelZip->locateName('beta/evidence.json'));
            self::assertNotFalse($laravelZip->locateName('examples/overselling/routes.php'));

            for ($index = 0; $index < $laravelZip->numFiles; $index++) {
                $filename = $laravelZip->getNameIndex($index);
                self::assertIsString($filename);
                self::assertDoesNotMatchRegularExpression(
                    '#^(?:\\.git|\\.github|build|tests|tools|vendor)/#',
                    $filename,
                );
            }
        } finally {
            $laravelZip->close();
        }

        $runtimeZip = new ZipArchive;
        self::assertTrue($runtimeZip->open($first['runtime']['path']));

        try {
            self::assertNotFalse($runtimeZip->locateName('LICENSE'));
            self::assertNotFalse($runtimeZip->locateName('src/helpers.php'));

            for ($index = 0; $index < $runtimeZip->numFiles; $index++) {
                $filename = $runtimeZip->getNameIndex($index);
                self::assertIsString($filename);
                self::assertFalse(str_starts_with($filename, 'vendor/'));
                self::assertFalse(str_starts_with($filename, 'tests/'));
            }
        } finally {
            $runtimeZip->close();
        }
    }

    public function test_release_workflow_is_fail_closed_and_runtime_first(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root.'/.github/workflows/release.yml');
        $testsWorkflow = file_get_contents($root.'/.github/workflows/tests.yml');

        self::assertIsString($workflow);
        self::assertIsString($testsWorkflow);
        self::assertIsArray(Yaml::parse($workflow));
        self::assertIsArray(Yaml::parse($testsWorkflow));
        self::assertStringContainsString('git verify-tag "$GITHUB_REF_NAME"', $workflow);
        self::assertGreaterThanOrEqual(2, substr_count($workflow, 'verify-tag "$GITHUB_REF_NAME"'));
        self::assertStringContainsString('config user.signingkey "${{ steps.gpg.outputs.fingerprint }}"', $workflow);
        self::assertStringContainsString('git merge-base --is-ancestor "$GITHUB_SHA" origin/main', $workflow);
        self::assertStringContainsString('composer api:check', $workflow);
        self::assertStringContainsString('Verify required CI on the exact release commit', $workflow);
        self::assertStringContainsString("grep -Fx 'release-audit'", $workflow);
        self::assertStringContainsString('composer release:audit', $workflow);
        self::assertStringContainsString('composer release:gate', $workflow);
        self::assertStringContainsString('if [[ "$version" != *-* ]]', $workflow);
        self::assertStringContainsString('release-audit:', $testsWorkflow);
        self::assertStringContainsString('secret-scan:', $testsWorkflow);
        self::assertStringContainsString('fetch-depth: 0', $testsWorkflow);
        self::assertStringContainsString('gitleaks dir . --no-banner --redact', $testsWorkflow);
        self::assertStringContainsString('gitleaks git . --no-banner --redact', $testsWorkflow);
        self::assertStringContainsString('- secret-scan', $testsWorkflow);
        self::assertStringContainsString('- release-dry-run', $testsWorkflow);
        self::assertStringContainsString('- database', $testsWorkflow);
        self::assertStringContainsString('composer update --with', $testsWorkflow);
        self::assertStringContainsString('--with-all-dependencies', $testsWorkflow);
        self::assertStringNotContainsString('composer require --no-update', $testsWorkflow);
        self::assertStringContainsString('SHA256SUMS.asc', $workflow);
        self::assertStringContainsString('provenance.json.asc', $workflow);

        $runtimeRelease = strpos($workflow, 'Publish raceproof/runtime GitHub release');
        $runtimePackagist = strpos($workflow, 'Publish and verify raceproof/runtime on Packagist');
        $laravelRelease = strpos($workflow, 'Publish raceproof/laravel GitHub release');
        $laravelPackagist = strpos($workflow, 'Publish, verify, and install raceproof/laravel from Packagist');

        self::assertIsInt($runtimeRelease);
        self::assertIsInt($runtimePackagist);
        self::assertIsInt($laravelRelease);
        self::assertIsInt($laravelPackagist);
        self::assertLessThan($runtimePackagist, $runtimeRelease);
        self::assertLessThan($laravelRelease, $runtimePackagist);
        self::assertLessThan($laravelPackagist, $laravelRelease);

        foreach ([$workflow, $testsWorkflow] as $workflowContents) {
            preg_match_all('/uses:\s+[^\\s]+@([^\\s#]+)/', $workflowContents, $matches);
            self::assertNotSame([], $matches[1]);

            foreach ($matches[1] as $reference) {
                self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $reference);
            }
        }
    }

    /** @return array<string, mixed> */
    private function archiveManifest(string $archive): array
    {
        $zip = new ZipArchive;
        self::assertTrue($zip->open($archive));

        try {
            $contents = $zip->getFromName('composer.json');
            self::assertIsString($contents);

            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return $manifest;
        } finally {
            $zip->close();
        }
    }
}
