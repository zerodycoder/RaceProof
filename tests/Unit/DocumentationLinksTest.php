<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DocumentationLinksTest extends TestCase
{
    public function test_every_published_local_markdown_link_resolves(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root.'/*.md');

        self::assertIsArray($files);

        foreach (['docs', 'examples'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$directory),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $missing = [];

        foreach ($files as $file) {
            $markdown = file_get_contents($file);

            self::assertIsString($markdown);
            preg_match_all('/\[[^\]]+]\(([^)]+)\)/', $markdown, $matches);

            foreach ($matches[1] as $target) {
                if (
                    str_starts_with($target, '#')
                    || str_starts_with($target, 'https://')
                    || str_starts_with($target, 'http://')
                    || str_starts_with($target, 'mailto:')
                ) {
                    continue;
                }

                $path = explode('#', rawurldecode($target), 2)[0];

                if ($path !== '' && ! is_file(dirname($file).'/'.$path)) {
                    $missing[] = str_replace('\\', '/', substr($file, strlen($root) + 1)).' -> '.$target;
                }
            }
        }

        self::assertSame([], $missing, "Broken local documentation links:\n".implode("\n", $missing));
    }
}
