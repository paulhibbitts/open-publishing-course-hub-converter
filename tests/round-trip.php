<?php
// Round-trip tests: converts each sample in tests/samples to the other format and back, then checks the result.
// Run from the converter's folder, before committing a change:  php tests/round-trip.php
// Every check should say "passed"; anything else lists what differs.
//
// The samples are small copies of public starter content (smaller images – the converter only copies them):
//   grav-guides    – the Grav Open Publishing Space skeleton's two guides (also tested renamed to guide.md and guide-list.md)
//   grav-extras    – a tiny guide with cases that once came back changed (Markdown-version links, banners, classes …)
//   grav-course    – the Grav Open Course Hub skeleton's course
//   docsify-guide  – the Docsify-This Guide Site template
//   docsify-course – the Docsify Open Course Starter Kit (its docs folder, without assets)
// To update a sample after a skeleton, template or kit changes on purpose, copy its new files over the old ones.

if (PHP_SAPI !== 'cli') {
    exit; // command line only – nothing runs if this file is ever opened in a browser
}

$samples = __DIR__ . '/samples';
$work = sys_get_temp_dir() . '/converter-round-trip-' . getmypid();
mkdir($work);

$failed = 0;

// 1. Grav guides → Docsify → Grav: the same files as the original
convert('grav2docsify', "$samples/grav-guides", "$work/guides-docsify");
convert('docsify2grav', "$work/guides-docsify", "$work/guides-back");
$failed += report('Grav guides come back identical', sameFiles("$samples/grav-guides", "$work/guides-back"));

// 1b. A tiny guide with cases that once came back changed: the same files as the original
convert('grav2docsify', "$samples/grav-extras", "$work/extras-docsify", 'single');
convert('docsify2grav', "$work/extras-docsify", "$work/extras-back");
$failed += report('Grav test guide comes back identical', sameFiles("$samples/grav-extras", "$work/extras-back"));

// 1c. The same guides with the newer page names (guide.md for each guide, guide-list.md for the Guides page):
//     they come back with those names
copyFolder("$samples/grav-guides", "$work/renamed");
rename("$work/renamed/00.guides/reader-list.md", "$work/renamed/00.guides/guide-list.md");
rename("$work/renamed/01.open-education-essentials/section-list.md", "$work/renamed/01.open-education-essentials/guide.md");
rename("$work/renamed/02.sample-guide/section-list.md", "$work/renamed/02.sample-guide/guide.md");
convert('grav2docsify', "$work/renamed", "$work/renamed-docsify");
convert('docsify2grav', "$work/renamed-docsify", "$work/renamed-back");
$failed += report('Grav guides with the newer page names come back identical', sameFiles("$work/renamed", "$work/renamed-back"));

// 2. Grav course → Docsify → Grav: the same files as the original
convert('course2docsify', "$samples/grav-course", "$work/course-docsify");
convert('course2grav', "$work/course-docsify", "$work/course-back");
$failed += report('Grav course comes back identical', sameFiles("$samples/grav-course", "$work/course-back"));

// 3. Docsify guide → Grav → Docsify: the same text for readers (the converter's hidden notes aside)
convert('docsify2grav', "$samples/docsify-guide", "$work/guide-grav");
convert('grav2docsify', "$work/guide-grav", "$work/guide-back", 'single');
$failed += report('Docsify guide comes back with the same text', sameText("$samples/docsify-guide", "$work/guide-back"));

// 4. Docsify course → Grav, then Grav → Docsify → Grav: the second Grav version is the same as the first
convert('course2grav', "$samples/docsify-course", "$work/kit-grav");
convert('course2docsify', "$work/kit-grav", "$work/kit-docsify");
convert('course2grav', "$work/kit-docsify", "$work/kit-grav-again");
$failed += report('Docsify course stays the same after another round trip', sameFiles("$work/kit-grav", "$work/kit-grav-again"));

removeFolder($work);
if ($failed === 0) {
    echo "\nAll checks passed.\n";
    exit(0);
}
echo "\n$failed check(s) failed.\n";
exit(1);

// ---- helpers ----

// Runs one conversion with the converter's own command-line tool
function convert(string $direction, string $from, string $to, string $option = ''): void
{
    $command = 'php -d display_errors=0 ' . escapeshellarg(dirname(__DIR__) . '/cli-test.php') . ' ' . $direction . ' '
        . escapeshellarg($from) . ' ' . escapeshellarg($to);
    if ($option !== '') {
        $command .= ' ' . $option;
    }
    $command .= ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0 && !is_dir($to)) {
        echo "Conversion failed: $direction $from\n" . implode("\n", $output) . "\n";
    }
}

// Prints one check's result, with up to 10 differences; returns 1 if it failed
function report(string $name, array $differences): int
{
    if (count($differences) === 0) {
        echo "passed  $name\n";
        return 0;
    }
    echo "FAILED  $name (" . count($differences) . " differences)\n";
    foreach (array_slice($differences, 0, 10) as $difference) {
        echo "          $difference\n";
    }
    return 1;
}

// Every file in both folders, compared byte for byte (a missing last line break doesn't count)
function sameFiles(string $original, string $back): array
{
    $differences = [];
    $a = filesIn($original);
    $b = filesIn($back);
    foreach (array_diff($a, $b) as $file) {
        $differences[] = "missing: $file";
    }
    foreach (array_diff($b, $a) as $file) {
        $differences[] = "added: $file";
    }
    foreach (array_intersect($a, $b) as $file) {
        if (rtrim(file_get_contents("$original/$file")) !== rtrim(file_get_contents("$back/$file"))) {
            $differences[] = "changed: $file";
        }
    }
    return $differences;
}

// Each Markdown page's text, without the converter's hidden notes (<!-- grav… -->) and blank lines
function sameText(string $original, string $back): array
{
    $differences = [];
    foreach (filesIn($original) as $file) {
        if (!str_ends_with($file, '.md')) {
            continue;
        }
        if (!is_file("$back/$file")) {
            $differences[] = "missing: $file";
        } elseif (visibleText("$original/$file") !== visibleText("$back/$file")) {
            $differences[] = "changed: $file";
        }
    }
    return $differences;
}

function visibleText(string $path): array
{
    $text = preg_replace('/<!-- \/?grav.*?-->/s', '', str_replace("\r\n", "\n", file_get_contents($path)));
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        if (trim($line) !== '') {
            $lines[] = rtrim($line);
        }
    }
    return $lines;
}

// A folder's files, as paths inside it (the converter's notes and hidden system files left out)
function filesIn(string $folder): array
{
    $files = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $name = $item->getFilename();
        if ($name === 'conversion-notes.txt' || str_starts_with($name, '.')) {
            continue;
        }
        $files[] = substr($item->getPathname(), strlen($folder) + 1);
    }
    sort($files);
    return $files;
}

// Deletes the test's own temporary folder (and only a folder it made)
function removeFolder(string $folder): void
{
    if (!str_contains($folder, 'converter-round-trip-')) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($folder);
}

// A copy of a folder and everything in it
function copyFolder(string $from, string $to): void
{
    mkdir($to, 0777, true);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            mkdir($target, 0777, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}
