<?php

declare(strict_types=1);

namespace RaceProof\ReleaseTools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use ZipArchive;

final class Release
{
    private const ARCHIVE_TIMESTAMP = 946684800;

    private const VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|rc)\.(0|[1-9]\d*))?$/D';

    /** @return array{major:int, minor:int, patch:int, prerelease:string|null} */
    public static function version(string $version): array
    {
        if (preg_match(self::VERSION_PATTERN, $version, $matches) !== 1) {
            throw new RuntimeException("Invalid release version: {$version}");
        }

        return [
            'major' => (int) $matches[1],
            'minor' => (int) $matches[2],
            'patch' => (int) $matches[3],
            'prerelease' => isset($matches[4], $matches[5]) ? $matches[4].'.'.$matches[5] : null,
        ];
    }

    public static function runtimeConstraint(string $version): string
    {
        $parsed = self::version($version);

        if ($parsed['prerelease'] !== null) {
            return $version.'@'.self::minimumStability($version);
        }

        return '^'.$parsed['major'].'.'.$parsed['minor'];
    }

    public static function minimumStability(string $version): string
    {
        $prerelease = self::version($version)['prerelease'];

        return match (true) {
            $prerelease === null => 'stable',
            str_starts_with($prerelease, 'alpha.') => 'alpha',
            str_starts_with($prerelease, 'beta.') => 'beta',
            str_starts_with($prerelease, 'rc.') => 'RC',
            default => throw new RuntimeException("Unsupported prerelease stability: {$prerelease}"),
        };
    }

    public static function argument(int $index, string $default = ''): string
    {
        $arguments = $_SERVER['argv'] ?? [];
        $value = is_array($arguments) ? ($arguments[$index] ?? null) : null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array{
     *     directory:string,
     *     laravel:array{path:string, sha256:string},
     *     runtime:array{path:string, sha256:string},
     *     checksums:string
     * }
     */
    public static function buildArtifacts(string $root, string $version, string $directory): array
    {
        self::version($version);
        self::resetDirectory($root, $directory);

        $runtimeArchive = $directory."/raceproof-runtime-{$version}.zip";
        $laravelArchive = $directory."/raceproof-laravel-{$version}.zip";
        $runtimeManifest = self::manifest($root.'/runtime/composer.json');
        $runtimeManifest['version'] = $version;
        $laravelManifest = self::manifest($root.'/composer.json');
        $laravelManifest['version'] = $version;
        $laravelRequirements = $laravelManifest['require'] ?? null;

        if (! is_array($laravelRequirements)) {
            throw new RuntimeException('The Laravel package manifest must contain a require object.');
        }

        $laravelRequirements['raceproof/runtime'] = self::runtimeConstraint($version);
        $laravelManifest['require'] = $laravelRequirements;
        unset(
            $laravelManifest['autoload-dev'],
            $laravelManifest['config'],
            $laravelManifest['minimum-stability'],
            $laravelManifest['prefer-stable'],
            $laravelManifest['repositories'],
            $laravelManifest['require-dev'],
            $laravelManifest['scripts'],
        );

        self::archive(
            base: $root.'/runtime',
            archive: $runtimeArchive,
            paths: ['LICENSE.md', 'README.md', 'composer.json', 'src'],
            manifest: $runtimeManifest,
        );
        self::archive(
            base: $root,
            archive: $laravelArchive,
            paths: [
                'CHANGELOG.md',
                'CONTRIBUTING.md',
                'LICENSE.md',
                'README.md',
                'ROADMAP.md',
                'SECURITY.md',
                'SUPPORT.md',
                'UPGRADING.md',
                'api',
                'beta',
                'composer.json',
                'config',
                'docs',
                'examples',
                'src',
            ],
            manifest: $laravelManifest,
        );

        $runtimeHash = hash_file('sha256', $runtimeArchive);
        $laravelHash = hash_file('sha256', $laravelArchive);

        if (! is_string($runtimeHash) || ! is_string($laravelHash)) {
            throw new RuntimeException('Unable to hash release archives.');
        }

        $checksums = implode("\n", [
            "{$laravelHash}  ".basename($laravelArchive),
            "{$runtimeHash}  ".basename($runtimeArchive),
            '',
        ]);
        $checksumsPath = $directory.'/SHA256SUMS';

        if (file_put_contents($checksumsPath, $checksums, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$checksumsPath}.");
        }

        return [
            'directory' => $directory,
            'laravel' => ['path' => $laravelArchive, 'sha256' => $laravelHash],
            'runtime' => ['path' => $runtimeArchive, 'sha256' => $runtimeHash],
            'checksums' => $checksumsPath,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function writeJson(string $path, array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$path}.");
        }
    }

    /** @param list<string> $command */
    public static function run(array $command, string $workingDirectory, int $timeout = 180): string
    {
        $process = new Process($command, $workingDirectory, timeout: $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "Command failed (%s):\n%s%s",
                implode(' ', $command),
                $process->getOutput(),
                $process->getErrorOutput(),
            ));
        }

        return $process->getOutput();
    }

    public static function resetDirectory(string $root, string $directory): void
    {
        $buildRoot = self::normalized($root.'/build/release');
        $target = self::normalized($directory);

        if (! str_starts_with($target.'/', $buildRoot.'/') || $target === $buildRoot) {
            throw new RuntimeException("Refusing to reset release directory outside {$buildRoot}: {$target}");
        }

        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo) {
                    throw new RuntimeException('Release cleanup encountered an invalid filesystem entry.');
                }

                if ($entry->isDir()) {
                    if (! rmdir($entry->getPathname())) {
                        throw new RuntimeException("Unable to remove directory {$entry->getPathname()}.");
                    }
                } elseif (! unlink($entry->getPathname())) {
                    throw new RuntimeException("Unable to remove file {$entry->getPathname()}.");
                }
            }

            if (! rmdir($directory)) {
                throw new RuntimeException("Unable to remove directory {$directory}.");
            }
        }

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $manifest
     */
    private static function archive(string $base, string $archive, array $paths, array $manifest): void
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = $base.'/'.$path;

            if (is_file($absolute)) {
                $files[] = $path;

                continue;
            }

            if (! is_dir($absolute)) {
                throw new RuntimeException("Release input does not exist: {$absolute}");
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo) {
                    throw new RuntimeException('Release packaging encountered an invalid filesystem entry.');
                }

                if ($entry->isFile()) {
                    $files[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
                }
            }
        }

        sort($files);
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create {$archive}.");
        }

        try {
            foreach ($files as $file) {
                $contents = $file === 'composer.json'
                    ? json_encode(
                        $manifest,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    )."\n"
                    : file_get_contents($base.'/'.$file);

                if (! is_string($contents) || ! $zip->addFromString($file, $contents)) {
                    throw new RuntimeException("Unable to add {$file} to {$archive}.");
                }

                $zip->setMtimeName($file, self::ARCHIVE_TIMESTAMP);
                $zip->setCompressionName($file, ZipArchive::CM_DEFLATE, 9);
            }
        } finally {
            $zip->close();
        }
    }

    private static function normalized(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function __construct() {}
}
