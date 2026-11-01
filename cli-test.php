<?php
// Runs the converter from the command line, for testing on your own sites:
//   php cli-test.php grav2docsify <pages folder> <output folder> [single] [site=https://original-site]
//   php cli-test.php docsify2grav <Docsify site folder> <output pages folder>
// The Docsify output works at the top of a repo shown by Docsify-This, or in a Starter Kit's docs/ folder.
// only from the command line: on the web server it does nothing
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use OPG\DocsifyReader;
use OPG\DocsifyWriter;
use OPG\GravReader;
use OPG\GravWriter;

$usage = "Usage: php cli-test.php grav2docsify|docsify2grav <source folder> <output folder> [single] [site=https://original-site]\n";
if (count($argv) < 4) {
    fwrite(STDERR, $usage);
    exit(1);
}
$direction = $argv[1];
$source = rtrim($argv[2], '/');
$out = rtrim($argv[3], '/');
if ($direction !== 'grav2docsify' && $direction !== 'docsify2grav') {
    fwrite(STDERR, $usage);
    exit(1);
}
if (!is_dir($source)) {
    fwrite(STDERR, "No such folder: $source\n");
    exit(1);
}

// the optional words after the folders
$single = false;
$siteUrl = '';
for ($i = 4; $i < count($argv); $i++) {
    if ($argv[$i] === 'single') {
        $single = true;
    } elseif (str_starts_with($argv[$i], 'site=')) {
        $siteUrl = substr($argv[$i], strlen('site='));
    }
}

if ($direction === 'docsify2grav') {
    $site = (new DocsifyReader())->read($source);
    (new GravWriter())->write($site, $out);
    echo 'Converted ' . count($site->guides) . " guide(s) to $out\n";
    exit;
}

$site = (new GravReader())->read($source);
$writer = new DocsifyWriter();
$writer->write($site, $source, $out, $single, $siteUrl);
foreach ($writer->warnings as $warning) {
    echo 'Warning – ' . $warning . "\n";
}
$guideCount = count($site->guides);
if ($single) {
    $guideCount = 1;
}
echo 'Converted ' . $guideCount . " guide(s) to $out\n";
