<?php
// Runs the converter from the command line, for testing on your own sites.
//   php cli-test.php grav2docsify <pages folder> <output folder> [single] [site=https://original-site]
//   php cli-test.php docsify2grav <Docsify site folder> <output pages folder>
// The output works at the top of a repo shown by Docsify-This, or in a Starter Kit's docs/ folder.
require_once __DIR__ . '/vendor/autoload.php';

use OPG\DocsifyReader;
use OPG\DocsifyWriter;
use OPG\GravReader;
use OPG\GravWriter;

[$script, $direction, $source, $out] = array_pad($argv, 4, null);
$extra = array_slice($argv, 4);
$single = in_array('single', $extra, true);
$siteUrl = '';
foreach ($extra as $arg) {
    if (str_starts_with($arg, 'site=')) {
        $siteUrl = substr($arg, 5);
    }
}

if (!in_array($direction, ['grav2docsify', 'docsify2grav'], true) || !$source || !$out) {
    fwrite(STDERR, "Usage: php cli-test.php grav2docsify|docsify2grav <pages folder> <output folder> [single] [site=https://original-site]\n");
    exit(1);
}
if (!is_dir($source)) {
    fwrite(STDERR, "No such folder: $source\n");
    exit(1);
}

if ($direction === 'docsify2grav') {
    $site = (new DocsifyReader())->read($source);
    (new GravWriter())->write($site, rtrim($out, '/'));
    echo 'Converted ' . count($site->guides) . " guide(s) to $out\n";
    exit;
}

$site = (new GravReader())->read(rtrim($source, '/'));
$writer = new DocsifyWriter();
$writer->write($site, rtrim($source, '/'), rtrim($out, '/'), $single, $siteUrl);
foreach ($writer->warnings as $warning) {
    echo 'Warning – ' . $warning . "\n";
}
echo 'Converted ' . count($single ? array_slice($site->guides, 0, 1) : $site->guides) . " guide(s) to $out\n";
