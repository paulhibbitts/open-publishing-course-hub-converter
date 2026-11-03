<?php
// Runs the converter from the command line, for testing on your own sites:
//   php cli-test.php grav2docsify <pages folder> <output folder> [single] [site=https://original-site]
//   php cli-test.php docsify2grav <Docsify site folder> <output pages folder>
//   php cli-test.php course2docsify <Open Course Hub pages folder> <output folder> [site=https://original-site]
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

$usage = "Usage: php cli-test.php grav2docsify|docsify2grav|course2docsify|course2grav <source folder> <output folder> [single] [site=https://original-site]\n";
if (count($argv) < 4) {
    fwrite(STDERR, $usage);
    exit(1);
}
$direction = $argv[1];
$source = rtrim($argv[2], '/');
$out = rtrim($argv[3], '/');
if (!in_array($direction, ['grav2docsify', 'docsify2grav', 'course2docsify', 'course2grav'], true)) {
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

if ($direction === 'course2grav') {
    $reader = new OPG\CourseDocsifyReader();
    $site = $reader->read($source);
    (new OPG\CourseGravWriter())->write($site, $out);
    foreach ($reader->notes as $note) {
        echo 'Note – ' . $note . "\n";
    }
    echo 'Converted ' . count($site->modulesPage->children) . ' module(s) and ' . count($site->coursePages) . " menu page(s) to $out\n";
    exit;
}

if ($direction === 'course2docsify') {
    $reader = new OPG\CourseGravReader();
    $site = $reader->read($source);
    if ($site->modulesPage === null) {
        fwrite(STDERR, "No Modules page (module.md) was found in $source\n");
        exit(1);
    }
    $writer = new OPG\CourseDocsifyWriter();
    $writer->write($site, $source, $out, $siteUrl);
    foreach (array_merge($reader->notes, $writer->warnings) as $warning) {
        echo 'Note – ' . $warning . "\n";
    }
    echo 'Converted ' . count($site->modulesPage->children) . ' module(s) and ' . count($site->coursePages) . " menu page(s) to $out\n";
    exit;
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
