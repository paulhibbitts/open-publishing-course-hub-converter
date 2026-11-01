<?php
// Receives a zip of an Open Publishing Space site's pages, converts its guides to Docsify, and sends back a zip.
// Errors come back as plain text (with an error status), which the upload page shows.
require_once __DIR__ . '/vendor/autoload.php';

use OPG\DocsifyWriter;
use OPG\GravReader;
use OPG\Helpers;

set_time_limit(300);
ini_set('memory_limit', '512M');

function fail(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function removeFolder(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// When post_max_size is exceeded, PHP empties $_FILES and $_POST entirely
if (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail('The server rejected the upload – the file is probably larger than its limit (' . ini_get('post_max_size') . ').');
}
$upload = $_FILES['site'] ?? null;
if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) {
    fail('No file was selected.');
}
if ($upload['error'] !== UPLOAD_ERR_OK) {
    fail('The upload didn\'t finish (error code ' . $upload['error'] . '). Please try again.');
}
if ($upload['size'] > 200 * 1024 * 1024) {
    fail('The file is larger than the 200 MB limit.');
}

$work = sys_get_temp_dir() . '/opg-' . bin2hex(random_bytes(8));
$in = $work . '/in';
$out = $work . '/out';
mkdir($in, 0777, true);
mkdir($out, 0777, true);
// remove the temporary folder however the request ends (fail() exits, which skips try/finally)
register_shutdown_function(fn() => removeFolder($work));

$zip = new ZipArchive();
if ($zip->open($upload['tmp_name']) !== true) {
    fail('That file isn\'t a zip file. Zip your site\'s pages folder and upload the zip.');
}
// Only Markdown pages and images are needed; anything else (and any path that tries to leave the
// folder) is skipped
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '__MACOSX')) {
        continue;
    }
    if (!str_ends_with(strtolower($name), '.md') && !Helpers::isImage($name)) {
        continue;
    }
    $target = $in . '/' . $name;
    @mkdir(dirname($target), 0777, true);
    file_put_contents($target, $zip->getFromIndex($i));
}
$zip->close();

// The pages folder is the one whose sub-folders hold the guides (a section-list.md in each), wherever it
// sits in the zip: user/pages, pages, or the zip's top level
$pagesDir = null;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($in, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getFilename() === 'section-list.md') {
        $candidate = dirname($file->getPath());
        if ($pagesDir === null || strlen($candidate) < strlen($pagesDir)) {
            $pagesDir = $candidate;
        }
    }
}
if ($pagesDir === null) {
    fail('No Open Publishing Space guides were found in that zip. Each guide is a folder with a section-list.md page, inside your site\'s user/pages folder.');
}

$site = (new GravReader())->read($pagesDir);
if (!$site->guides) {
    fail('No Open Publishing Space guides were found in that zip.');
}
$single = !empty($_POST['single']);
(new DocsifyWriter())->write($site, $pagesDir, $out, $single);

$result = $work . '/result.zip';
$zip = new ZipArchive();
$zip->open($result, ZipArchive::CREATE);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($out) + 1));
}
$zip->close();

$base = preg_replace('/[^a-z0-9-]+/', '-', strtolower(pathinfo($upload['name'], PATHINFO_FILENAME))) ?: 'guides';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . trim($base, '-') . '-docsify.zip"');
header('Content-Length: ' . filesize($result));
header('X-Guides-Converted: ' . count($single ? array_slice($site->guides, 0, 1) : $site->guides));
readfile($result);
