<?php
// Receives a zip of an Open Publishing Space site's pages, converts its guides to Docsify, and sends back a zip.
// Errors come back as plain text (with an error status), which the upload page shows.
require_once __DIR__ . '/vendor/autoload.php';

use OPG\DocsifyWriter;
use OPG\GravReader;
use OPG\Helpers;

set_time_limit(300);
ini_set('memory_limit', '512M');

// Sends an error message back to the upload page and stops
function fail(string $message): void
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function removeFolder(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    // CHILD_FIRST lists a folder's contents before the folder itself, so each folder is empty when removed
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

// Unzips only what the converter needs (Markdown pages and images), skipping any path that tries to leave the folder
function unzipPages(string $zipFile, string $into): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        fail('That file isn\'t a zip file. Zip your site\'s pages folder and upload the zip.');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $isFolder = str_ends_with($name, '/');
        $isUnsafe = str_contains($name, '..') || str_starts_with($name, '/');
        $isMacExtra = str_contains($name, '__MACOSX');
        $isNeeded = str_ends_with(strtolower($name), '.md') || Helpers::isImage($name);
        if ($isFolder || $isUnsafe || $isMacExtra || !$isNeeded) {
            continue;
        }
        $target = $into . '/' . $name;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $zip->getFromIndex($i));
    }
    $zip->close();
}

// The pages folder is the one whose sub-folders hold the guides (a section-list.md in each), wherever it sits in
// the zip: user/pages, pages, or the zip's top level. Returns '' when there are no guides.
function findPagesFolder(string $dir): string
{
    $pagesFolder = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getFilename() !== 'section-list.md') {
            continue;
        }
        // section-list.md is in the guide's folder, which is in the pages folder
        $candidate = dirname($file->getPath());
        if ($pagesFolder === '' || strlen($candidate) < strlen($pagesFolder)) {
            $pagesFolder = $candidate;
        }
    }
    return $pagesFolder;
}

function zipFolder(string $dir, string $zipFile): void
{
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $nameInZip = substr($file->getPathname(), strlen($dir) + 1);
        $zip->addFile($file->getPathname(), $nameInZip);
    }
    $zip->close();
}

// ---- the upload ----

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// When the upload is larger than post_max_size, PHP empties $_FILES and $_POST entirely
$uploadSize = 0;
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $uploadSize = (int)$_SERVER['CONTENT_LENGTH'];
}
if (count($_FILES) === 0 && $uploadSize > 0) {
    fail('The server rejected the upload – the file is probably larger than its limit (' . ini_get('post_max_size') . ').');
}
if (!isset($_FILES['site']) || $_FILES['site']['error'] === UPLOAD_ERR_NO_FILE) {
    fail('No file was selected.');
}
$upload = $_FILES['site'];
if ($upload['error'] !== UPLOAD_ERR_OK) {
    fail('The upload didn\'t finish (error code ' . $upload['error'] . '). Please try again.');
}
if ($upload['size'] > 200 * 1024 * 1024) {
    fail('The file is larger than the 200 MB limit.');
}

// the options
$single = isset($_POST['single']) && $_POST['single'] !== '';
$siteUrl = '';
if (isset($_POST['site_url'])) {
    $siteUrl = trim((string)$_POST['site_url']);
}
if ($siteUrl !== '' && preg_match('#^https?://\S+$#', $siteUrl) !== 1) {
    fail('The original site address should start with https:// (for example https://example.org).');
}

// a temporary folder for this conversion, removed however the request ends (fail() exits, which would skip
// any clean-up written after it, so it's registered to run at shutdown)
$work = sys_get_temp_dir() . '/opg-' . bin2hex(random_bytes(8));
mkdir($work . '/in', 0777, true);
mkdir($work . '/out', 0777, true);
register_shutdown_function('removeFolder', $work);

unzipPages($upload['tmp_name'], $work . '/in');
$pagesFolder = findPagesFolder($work . '/in');
if ($pagesFolder === '') {
    fail('No Open Publishing Space guides were found in that zip. Each guide is a folder with a section-list.md page, inside your site\'s user/pages folder.');
}

// ---- the conversion ----

$site = (new GravReader())->read($pagesFolder);
if (count($site->guides) === 0) {
    fail('No Open Publishing Space guides were found in that zip.');
}
$writer = new DocsifyWriter();
$writer->write($site, $pagesFolder, $work . '/out', $single, $siteUrl);

$guides = $site->guides;
if ($single) {
    $guides = array_slice($site->guides, 0, 1);
}
$warnings = array_values(array_unique($writer->warnings));

// notes on what was converted, and anything to check, saved with the pages
$notes = ['Open Publishing Guides Converter – conversion notes', '', 'Guides converted: ' . count($guides)];
foreach ($guides as $guide) {
    $pageCount = 0;
    foreach ($guide->children as $section) {
        $pageCount += 1 + count($section->children);
    }
    $notes[] = '- ' . $guide->title() . ' (' . count($guide->children) . ' sections, ' . $pageCount . ' pages)';
}
$notes[] = '';
if (count($warnings) > 0) {
    $notes[] = 'To check:';
    foreach ($warnings as $warning) {
        $notes[] = '- ' . $warning;
    }
} else {
    $notes[] = 'Nothing to check.';
}
file_put_contents($work . '/out/conversion-notes.txt', implode("\n", $notes) . "\n");

// ---- the download ----

$resultZip = $work . '/result.zip';
zipFolder($work . '/out', $resultZip);

$baseName = strtolower(pathinfo($upload['name'], PATHINFO_FILENAME));
$baseName = trim(preg_replace('/[^a-z0-9-]+/', '-', $baseName), '-');
if ($baseName === '') {
    $baseName = 'guides';
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $baseName . '-docsify.zip"');
header('Content-Length: ' . filesize($resultZip));
// the page reads these to say what happened
header('X-Guides-Converted: ' . count($guides));
header('X-Warnings: ' . count($warnings));
readfile($resultZip);
