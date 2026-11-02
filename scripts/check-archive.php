<?php

/**
 * Checks the package archive `composer archive` built: what users install
 * must hold the library and its documents, and none of the development
 * files .gitattributes marks export-ignore.
 *
 *     php scripts/check-archive.php build/invoist.tar
 */

declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];
$path = is_array($argv) && is_string($argv[1] ?? null) ? $argv[1] : '';
if (!is_file($path)) {
    fwrite(STDERR, "check-archive: no archive at \"$path\"\n");
    exit(2);
}

$files = [];
$prefix = 'phar://' . realpath($path) . '/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file instanceof SplFileInfo) {
        $files[] = substr($file->getPathname(), strlen($prefix));
    }
}
sort($files);

$problems = [];
$required = ['composer.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'SECURITY.md', 'src/Invoist.php', 'src/Generated/Bill.php'];
foreach ($required as $name) {
    if (!in_array($name, $files, true)) {
        $problems[] = "missing $name";
    }
}
foreach ($files as $file) {
    if (preg_match('#^(tests|testdata|scripts|vendor|build)/|^\.|^(phpunit|phpstan|phpcs)\.|^composer\.lock$#', $file) === 1) {
        $problems[] = "should not ship $file";
    }
}
if ($problems !== []) {
    fwrite(STDERR, "check-archive: $path is not right:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}
echo 'check-archive: ' . count($files) . " files, library and documents only\n";
