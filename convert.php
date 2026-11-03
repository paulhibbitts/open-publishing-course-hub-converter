<?php
// Converts guides and sends back a zip: a Docsify guide site in a GitHub repo becomes Open Publishing Space pages,
// and an uploaded zip of Open Publishing Space pages becomes Docsify pages.
// Errors come back as plain text (with an error status), which the upload page shows.
require_once __DIR__ . '/vendor/autoload.php';

use OPG\CourseDocsifyReader;
use OPG\CourseDocsifyWriter;
use OPG\CourseGravReader;
use OPG\CourseGravWriter;
use OPG\DocsifyReader;
use OPG\DocsifyWriter;
use OPG\GravReader;
use OPG\GravWriter;
use OPG\Helpers;
use OPG\Preview;
use OPG\Site;

set_time_limit(300);
ini_set('memory_limit', '512M');

// true when the page asks for a preview (sample pages as JSON) instead of the zip
$previewMode = isset($_POST['preview']) && $_POST['preview'] !== '';

// Sends an error message back to the page and stops (as JSON for a preview)
function fail(string $message): void
{
    global $previewMode;
    http_response_code(400);
    // the message is only ever shown as text
    header('X-Content-Type-Options: nosniff');
    if ($previewMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
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
    // a small zip can unpack to a huge size (a "zip bomb"), so the number of files and their unzipped size are capped
    if ($zip->numFiles > 20000) {
        fail('That zip has too many files (more than 20,000).');
    }
    $unzippedSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $unzippedSize += $entry['size'];
    }
    if ($unzippedSize > 500 * 1024 * 1024) {
        fail('That zip unpacks to more than 500 MB.');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $isFolder = str_ends_with($name, '/');
        $isUnsafe = str_contains($name, '..') || str_starts_with($name, '/');
        $isMacExtra = str_contains($name, '__MACOSX');
        // Markdown pages, their images, and a Starter Kit's index.html (only read for the site's name)
        $isNeeded = str_ends_with(strtolower($name), '.md') || Helpers::isImage($name) || basename($name) === 'index.html';
        if ($isFolder || $isUnsafe || $isMacExtra || !$isNeeded) {
            continue;
        }
        $target = $into . '/' . $name;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
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

// An Open Course Hub course's pages folder is the one with the Modules page's folder (module.md) in it, wherever it
// sits in the zip. Returns '' when there isn't one.
function findCoursePagesFolder(string $dir): string
{
    $pagesFolder = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getFilename() !== 'module.md') {
            continue;
        }
        $candidate = dirname($file->getPath());
        if ($pagesFolder === '' || strlen($candidate) < strlen($pagesFolder)) {
            $pagesFolder = $candidate;
        }
    }
    return $pagesFolder;
}

// A Docsify course (one course, laid out like the Docsify Open Course Starter Kit): its own sidebar lists module or
// week pages (module-01.md, week-1), or its pages were converted from Open Course Hub
function isDocsifyCourse(string $docsifyFolder): bool
{
    $sidebar = $docsifyFolder . '/_sidebar.md';
    if (is_file($sidebar) && preg_match_all('/\]\((?:\.\/)?(?:module|week)-?[0-9]+(?:\.md)?\)/i', (string)file_get_contents($sidebar)) >= 2) {
        return true;
    }
    foreach (glob($docsifyFolder . '/*.md') as $path) {
        if (str_contains((string)file_get_contents($path, false, null, 0, 300), 'course-page.md"')) {
            return true;
        }
    }
    return false;
}

// Any course site: Open Course Hub pages (a Modules page, module.md), or a Docsify course – a sidebar listing module or
// week pages (module-01.md, week-1), or pages converted from a course. Used to tell a MultiCourse site (courses in
// folders) from a guide site.
function isCourseSite(string $dir): bool
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $name = $file->getFilename();
        if ($name === 'module.md') {
            return true;
        }
        if ($name === '_sidebar.md') {
            // links such as (module-01.md), (./week-1) or (cpt363-1/module-01) – a folder before the name is fine
            $moduleLinks = preg_match_all('/\]\((?:[^)\s\/]+\/)*(?:module|week)-?[0-9]+(?:\.md)?\)/i', (string)file_get_contents($file->getPathname()));
            if ($moduleLinks >= 2) {
                return true;
            }
        }
        if (str_ends_with($name, '.md') && str_contains((string)file_get_contents($file->getPathname(), false, null, 0, 300), 'course-page.md"')) {
            return true;
        }
    }
    return false;
}

// A Docsify guide site's folder is the one with home.md (and _sidebar.md files), wherever it sits in the zip:
// the top, a repo folder, or a Starter Kit's docs/ folder. Returns '' when there isn't one.
function findDocsifyFolder(string $dir): string
{
    $docsifyFolder = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getFilename() !== 'home.md') {
            continue;
        }
        $candidate = $file->getPath();
        if ($docsifyFolder === '' || strlen($candidate) < strlen($docsifyFolder)) {
            $docsifyFolder = $candidate;
        }
    }
    return $docsifyFolder;
}

// Saves conversion-notes.txt: what was converted, anything to check, and any tips. $course: a course's modules and
// menu pages, listed instead of guides.
function writeNotes(string $file, array $guides, array $warnings, array $tips = [], ?Site $course = null): void
{
    $notes = ['Open Publishing Course Hub Converter – conversion notes', ''];
    if ($course !== null) {
        $notes[] = 'Course converted: ' . count($course->modulesPage->children) . ' modules and ' . count($course->coursePages) . ' menu pages';
        foreach ($course->modulesPage->children as $module) {
            $notes[] = '- ' . $module->title();
        }
    } else {
        $notes[] = 'Guides converted: ' . count($guides);
    }
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
    foreach ($tips as $tip) {
        $notes[] = '';
        $notes[] = 'Tip: ' . $tip;
    }
    file_put_contents($file, implode("\n", $notes) . "\n");
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

// Reads a GitHub address into its parts, or returns null when it isn't one. Accepts
//   https://github.com/owner/repo                       (the repo's default branch)
//   https://github.com/owner/repo/tree/main/docs        (a branch, and a folder in it)
//   https://raw.githubusercontent.com/owner/repo/main/docs
//   a docsify-this.net link, whose basePath is one of the addresses above
function parseGitHubAddress(string $address): ?array
{
    $address = trim($address);
    // a docsify-this.net link: the repo is in its basePath
    $query = parse_url($address, PHP_URL_QUERY);
    if (is_string($query) && str_contains($address, 'docsify-this')) {
        parse_str($query, $parameters);
        if (isset($parameters['basePath']) && is_string($parameters['basePath'])) {
            $address = $parameters['basePath'];
        }
    }

    // owner and repo names only use letters, numbers, - _ and .
    $name = '[A-Za-z0-9_.-]+';
    if (preg_match('#^https?://github\.com/(' . $name . ')/(' . $name . ')(?:/tree/(' . $name . ')(/[^?\#]*)?)?/?$#', $address, $found) === 1) {
        $branch = '';
        $folder = '';
        if (isset($found[3])) {
            $branch = $found[3];
        }
        if (isset($found[4])) {
            $folder = $found[4];
        }
        return ['owner' => $found[1], 'repo' => preg_replace('/\.git$/', '', $found[2]), 'branch' => $branch, 'folder' => trim($folder, '/')];
    }
    if (preg_match('#^https?://raw\.githubusercontent\.com/(' . $name . ')/(' . $name . ')/(' . $name . ')(/[^?\#]*)?/?$#', $address, $found) === 1) {
        $folder = '';
        if (isset($found[4])) {
            $folder = $found[4];
        }
        return ['owner' => $found[1], 'repo' => $found[2], 'branch' => $found[3], 'folder' => trim($folder, '/')];
    }
    return null;
}

// Downloads a GitHub repo as a zip (only ever from GitHub, at an address built here, never one sent in)
function downloadGitHubZip(array $repo, string $target): void
{
    if (!function_exists('curl_init')) {
        fail('This server can\'t download from GitHub. Download the repo as a zip on GitHub (Code → Download ZIP) and upload that instead.');
    }
    // HEAD is the repo's default branch
    $branch = $repo['branch'];
    if ($branch === '') {
        $branch = 'HEAD';
    }
    $url = 'https://github.com/' . rawurlencode($repo['owner']) . '/' . rawurlencode($repo['repo']) . '/archive/' . rawurlencode($branch) . '.zip';

    $file = fopen($target, 'w');
    $download = curl_init($url);
    curl_setopt($download, CURLOPT_FILE, $file);
    // GitHub sends the zip from codeload.github.com, so its redirect is followed (HTTPS only)
    curl_setopt($download, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($download, CURLOPT_MAXREDIRS, 3);
    curl_setopt($download, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($download, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($download, CURLOPT_TIMEOUT, 120);
    curl_setopt($download, CURLOPT_USERAGENT, 'Open Publishing Course Hub Converter');
    // stop a download that grows past the 200 MB limit
    curl_setopt($download, CURLOPT_NOPROGRESS, false);
    curl_setopt($download, CURLOPT_PROGRESSFUNCTION, 'stopLargeDownloads');
    $finished = curl_exec($download);
    $status = curl_getinfo($download, CURLINFO_RESPONSE_CODE);
    curl_close($download);
    fclose($file);

    if ($finished === false && filesize($target) > 200 * 1024 * 1024) {
        fail('That repo is larger than the 200 MB limit.');
    }
    if ($finished === false || $status !== 200) {
        fail('That repo couldn\'t be downloaded from GitHub. Check the address, and that the repo is public.');
    }
}

// Called by curl while downloading: returning anything but 0 stops the download
function stopLargeDownloads($download, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int
{
    if ($downloaded > 200 * 1024 * 1024) {
        return 1;
    }
    return 0;
}

// ---- the upload ----

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// When the upload is larger than post_max_size, PHP empties $_FILES and $_POST entirely (a repo address alone
// sends no file, but its $_POST isn't empty)
$uploadSize = 0;
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $uploadSize = (int)$_SERVER['CONTENT_LENGTH'];
}
if (count($_FILES) === 0 && count($_POST) === 0 && $uploadSize > 0) {
    fail('The server rejected the upload – the file is probably larger than its limit (' . ini_get('post_max_size') . ').');
}
// either a zip file or a GitHub repo address
$repoAddress = '';
if (isset($_POST['repo'])) {
    $repoAddress = trim((string)$_POST['repo']);
}
$hasFile = isset($_FILES['site']) && $_FILES['site']['error'] !== UPLOAD_ERR_NO_FILE;
$repo = null;
if (!$hasFile) {
    if ($repoAddress === '') {
        fail('Choose a zip file, or enter a GitHub repo address.');
    }
    $repo = parseGitHubAddress($repoAddress);
    if ($repo === null) {
        fail('That isn\'t a GitHub repo address. It should look like https://github.com/your-name/your-repo');
    }
} else {
    $upload = $_FILES['site'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        fail('The upload didn\'t finish (error code ' . $upload['error'] . '). Please try again.');
    }
    if ($upload['size'] > 200 * 1024 * 1024) {
        fail('The file is larger than the 200 MB limit.');
    }
    if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'zip') {
        fail('Please upload a .zip file of your site\'s user/pages folder.');
    }
    // the file's type is checked from its content, not just its name
    if (function_exists('finfo_open')) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $type = $fileInfo->file($upload['tmp_name']);
        if ($type !== 'application/zip' && $type !== 'application/x-zip-compressed') {
            fail('That file isn\'t a zip file. Zip your site\'s pages folder and upload the zip.');
        }
    }
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
// 0700: only the web server can read the uploaded pages while they're being converted
mkdir($work . '/in', 0700, true);
mkdir($work . '/out', 0700, true);
register_shutdown_function('removeFolder', $work);

$searchFolder = $work . '/in';
if ($repo !== null) {
    downloadGitHubZip($repo, $work . '/repo.zip');
    unzipPages($work . '/repo.zip', $work . '/in');
    // GitHub's zip holds one folder (repo-branch); a folder in the address (such as docs) is inside it
    $topFolders = glob($work . '/in/*', GLOB_ONLYDIR);
    if (count($topFolders) === 1 && $repo['folder'] !== '') {
        $searchFolder = $topFolders[0] . '/' . $repo['folder'];
        if (!is_dir($searchFolder)) {
            fail('The folder "' . $repo['folder'] . '" wasn\'t found in that repo.');
        }
    }
    $fileName = $repo['repo'];
} else {
    unzipPages($upload['tmp_name'], $work . '/in');
    $fileName = pathinfo($upload['name'], PATHINFO_FILENAME);
}

// ---- the conversion ----
// A repo address is a Docsify guide site or course, converted to Grav; an uploaded zip is Grav pages (Open Publishing
// Space guides or an Open Course Hub course), converted to Docsify.

$course = null;

if ($repo !== null) {
    $docsifyFolder = findDocsifyFolder($searchFolder);
    if ($docsifyFolder === '') {
        if (findPagesFolder($searchFolder) !== '') {
            fail('That repo has Grav pages, not a Docsify site. To convert them to Docsify, zip its pages folder and use "Grav to Docsify".');
        }
        fail('No Docsify guide site or course was found in that repo. It needs a home.md, and a _sidebar.md with the reading order or modules.');
    }
    $direction = 'grav';
    if (isDocsifyCourse($docsifyFolder)) {
        // a course: Open Course Hub pages
        $reader = new CourseDocsifyReader();
        $course = $reader->read($docsifyFolder);
        (new CourseGravWriter())->write($course, $work . '/out/pages');
        $guides = [];
        $warnings = array_values(array_unique($reader->notes));
        $tips = ['copy these pages into your Open Course Hub site\'s user/pages folder, in place of its home, modules, sidebar and footer folders'];
    }
} elseif (findCoursePagesFolder($searchFolder) !== '') {
    // an Open Course Hub course: a Docsify course, laid out like the Docsify Open Course Starter Kit
    $modulesPages = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($searchFolder, FilesystemIterator::SKIP_DOTS)), '/\/module\.md$/');
    if (iterator_count($modulesPages) > 1) {
        fail('That zip has several courses, like an Open MultiCourse Hub site. This converter works with one course (Open Course Hub), or with guides.');
    }
    $direction = 'docsify';
    $pagesFolder = findCoursePagesFolder($searchFolder);
    $reader = new CourseGravReader();
    $course = $reader->read($pagesFolder);
    $writer = new CourseDocsifyWriter();
    $writer->write($course, $pagesFolder, $work . '/out', $siteUrl);
    $guides = [];
    $warnings = array_values(array_unique(array_merge($reader->notes, $writer->warnings)));
    $tips = ['to use your Grav site\'s favicon, copy it from user/themes/mytheme/images/favicon/ into the Docsify site and add '
        . '&loadFavicon= with its file name (such as favicon.png) to the Docsify-This link'];
}

if ($repo !== null && $course === null) {
    if (isCourseSite($docsifyFolder)) {
        fail('That repo has courses in folders, like the Docsify Open MultiCourse Starter Kit. This converter works with one course, or with guides.');
    }
    $reader = new DocsifyReader();
    $site = $reader->read($docsifyFolder);
    if (count($site->guides) === 0) {
        fail('No guides were found in that Docsify site. Each guide needs a sidebar (_sidebar.md) with its reading order, and the pages it lists in the repo.');
    }
    // a pages folder, for the Grav site's user folder
    (new GravWriter())->write($site, $work . '/out/pages');
    $guides = $site->guides;
    $warnings = array_values(array_unique($reader->notes));
    // the site's home page: the Guides page, or a single guide's home when there's no Guides page
    $homeTitle = $site->guides[0]->title();
    $homePage = '/' . $site->guides[0]->slug();
    if ($site->guidesPage !== null) {
        $homeTitle = 'the ' . $site->guidesPage->title() . ' page';
        $homePage = '/' . $site->guidesPage->slug();
    }
    $tips = ['to start your site on ' . $homeTitle . ', set Home Page to ' . $homePage . ' in Configuration → System'];
} elseif ($course === null) {
    $pagesFolder = findPagesFolder($searchFolder);
    if ($pagesFolder === '') {
        if (findDocsifyFolder($searchFolder) !== '') {
            fail('That zip has a Docsify site, not Grav pages. To convert it to Grav, enter its GitHub repo address under "Docsify to Grav".');
        }
        fail('No Open Publishing Space guides or Open Course Hub modules were found in that zip. Zip your site\'s user/pages folder: each guide is a folder with a section-list.md page, and a course has a Modules page (module.md).');
    }
    $direction = 'docsify';
    $site = (new GravReader())->read($pagesFolder);
    $writer = new DocsifyWriter();
    $writer->write($site, $pagesFolder, $work . '/out', $single, $siteUrl);
    $guides = $site->guides;
    if ($single) {
        $guides = array_slice($site->guides, 0, 1);
    }
    $warnings = array_values(array_unique($writer->warnings));
    // the converter doesn't know the site's name or favicon (Grav keeps them outside the pages folder), so it can't add them
    $tips = ['to show your site\'s name at the top of pages without a sidebar (and on phones), add this as the first line of '
        . 'each _navbar.md: - [Your Site Name](home.md ":class=site-title")',
        'to use your Grav site\'s favicon, copy it from user/themes/mytheme/images/favicon/ into the Docsify site and add '
        . '&loadFavicon= with its file name (such as favicon.png) to the Docsify-This link'];
}
writeNotes($work . '/out/conversion-notes.txt', $guides, $warnings, $tips ?? [], $course);

// ---- a preview: sample pages instead of the zip ----

if ($previewMode) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    $preview = new Preview($work . '/out');
    $pages = $preview->samplePages($direction, $course !== null);
    if (count($pages) === 0) {
        fail('There were no pages to preview.');
    }
    echo json_encode(['direction' => $direction, 'pages' => $pages, 'warnings' => $warnings]);
    exit;
}

// ---- the download ----

$resultZip = $work . '/result.zip';
zipFolder($work . '/out', $resultZip);

$baseName = strtolower($fileName);
$baseName = trim(preg_replace('/[^a-z0-9-]+/', '-', $baseName), '-');
if ($baseName === '') {
    $baseName = 'guides';
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $baseName . '-' . $direction . '.zip"');
header('Content-Length: ' . filesize($resultZip));
// each download is made for one request, so it isn't kept in any cache
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');
// the page reads these to say what happened
header('X-Converted-To: ' . $direction);
header('X-Guides-Converted: ' . count($guides));
if ($course !== null) {
    header('X-Modules-Converted: ' . count($course->modulesPage->children));
}
header('X-Warnings: ' . count($warnings));
readfile($resultZip);
