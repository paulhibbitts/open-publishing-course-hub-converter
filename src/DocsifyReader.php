<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads a Docsify guide site (laid out like the Docsify-This guide templates) back into guides. Pages written by
// this converter carry a hidden note with their original Grav file and settings, so they go back unchanged.
class DocsifyReader
{
    private string $dir;
    // the original site's address, if one was given when converting to Docsify
    private string $siteUrl = '';
    // the single guide's address name, or '' for a site with a Guides page
    private string $singleGuide = '';
    // for a site made in Docsify (no hidden notes): settings filled in with defaults, and other things to check
    public array $notes = [];

    public function read(string $dir): Site
    {
        $this->dir = rtrim($dir, '/');

        // every Docsify page that has a hidden note
        $notedPages = [];
        foreach ($this->markdownFiles() as $path) {
            $note = Helpers::readGravNote(file_get_contents($path));
            if ($note !== null) {
                $note['path'] = $path;
                $notedPages[] = $note;
            }
        }

        if (count($notedPages) === 0) {
            // a site made in Docsify, not by this converter: its Grav structure is worked out from its layout
            $firstReader = new DocsifyFirstReader();
            $site = $firstReader->read($this->dir);
            $this->notes = $firstReader->notes;
            return $site;
        }

        foreach ($notedPages as $note) {
            // the original site's address (if one was given) is kept with the home page
            if (isset($note['where']['site'])) {
                $this->siteUrl = $note['where']['site'];
            }
            // in a single-guide site, home.md is the guide's home page
            $isHomePage = basename($note['path']) === 'home.md';
            if ($isHomePage && str_ends_with($note['where']['file'], 'section-list.md')) {
                $this->singleGuide = Helpers::slugFromFolder(basename(dirname($note['where']['file'])));
            }
        }
        return $this->fromNotes($notedPages);
    }

    // Rebuilds the guides from the hidden notes: each note says where its page goes in Grav
    private function fromNotes(array $notedPages): Site
    {
        $pagesByFolder = [];
        foreach ($notedPages as $note) {
            $gravFile = $note['where']['file'];
            $page = new Page($this->kindOf($gravFile), dirname($gravFile), basename($gravFile));
            $page->yaml = $note['yaml'];
            $settings = Yaml::parse($note['yaml']);
            if (is_array($settings)) {
                $page->meta = $settings;
            }
            $page->body = $this->authorText($page->kind, $note['rest']);
            $this->updateVisibleSettings($page, $note['rest']);

            $images = [];
            if (isset($note['where']['images'])) {
                $images = $note['where']['images'];
            }
            foreach ($images as $image) {
                // images are beside the Docsify page (a page's images are in its section's folder); only a plain
                // file name, for an image inside the site, is used
                $image = basename((string)$image);
                $source = Helpers::imageInside($this->dir, dirname($note['path']) . '/' . $image);
                if ($source !== '') {
                    $page->images[] = $image;
                    $page->imageSources[$image] = $source;
                }
            }
            $pagesByFolder[$page->folder] = $page;
        }

        // folders in Grav's order, so each guide's sections and pages are added in order
        ksort($pagesByFolder, SORT_NATURAL);
        $site = new Site();
        foreach ($pagesByFolder as $folder => $page) {
            $parent = null;
            if (isset($pagesByFolder[dirname($folder)])) {
                $parent = $pagesByFolder[dirname($folder)];
            }
            if ($page->kind === 'guides') {
                $site->guidesPage = $page;
            } elseif ($page->kind === 'guide') {
                $site->guides[] = $page;
            } elseif ($parent !== null && $page->kind === 'menu') {
                $parent->menuPages[] = $page;
            } elseif ($parent !== null) {
                $parent->children[] = $page;
            }
        }
        return $site;
    }

    // What a page is, from its Grav file: '01.guide/section-list.md' is a guide, '01.guide/01.section/section.md'
    // a section, '01.guide/topics/default.md' a menu page, and anything deeper a reading page
    private function kindOf(string $gravFile): string
    {
        $fileName = basename($gravFile);
        $folder = dirname($gravFile);
        $depth = count(explode('/', $folder));
        if ($fileName === 'reader-list.md') {
            return 'guides';
        }
        if ($fileName === 'section-list.md') {
            return 'guide';
        }
        if ($depth === 2 && !Helpers::isNumbered(basename($folder))) {
            return 'menu';
        }
        if ($depth === 2) {
            return 'section';
        }
        return 'page';
    }

    // The author's text: everything the converter didn't add, with callouts and links back in Grav's form
    private function authorText(string $kind, string $rest): string
    {
        // the parts between the converter's generated blocks
        $parts = preg_split('/<!-- grav:gen -->\n.*?\n<!-- \/grav:gen -->/s', $rest);
        $trimmedParts = [];
        foreach ($parts as $part) {
            $trimmedParts[] = trim($part);
        }

        if ($kind === 'guide' || $kind === 'guides') {
            // [before the title, the intro, after the cards, after the footer]; the text after the cards was
            // below a line of === in Grav
            $text = $this->partAt($trimmedParts, 1);
            $afterCards = $this->partAt($trimmedParts, 2);
            if (str_starts_with($afterCards, '<!-- grav:=== -->')) {
                $belowCards = trim(substr($afterCards, strlen('<!-- grav:=== -->')));
                $text = $text . "\n\n===\n\n" . $belowCards;
            }
        } else {
            // everything after the page's title block
            $textParts = [];
            for ($i = 1; $i < count($trimmedParts); $i++) {
                if ($trimmedParts[$i] !== '') {
                    $textParts[] = $trimmedParts[$i];
                }
            }
            $text = implode("\n\n", $textParts);
        }

        $codeParts = [];
        $text = Helpers::hideCode($text, $codeParts);
        $text = Callouts::toGrav($text);
        $text = Links::toGrav($text, $this->singleGuide, $this->siteUrl);
        $text = Links::newTabToGrav($text);
        return Helpers::showCode($text, $codeParts);
    }

    private function partAt(array $parts, int $index): string
    {
        if (isset($parts[$index])) {
            return $parts[$index];
        }
        return '';
    }

    // A title or subtitle changed in Docsify goes into the page's settings; the rest of the front matter is
    // kept exactly as it was
    private function updateVisibleSettings(Page $page, string $rest): void
    {
        // the first generated block holds the page's title (and a guide's subtitle)
        if (preg_match('/<!-- grav:gen -->\n(.*?)\n<!-- \/grav:gen -->/s', $rest, $match) !== 1) {
            return;
        }
        $titleBlock = $match[1];
        $changes = [];

        if (preg_match('/^# (.+)$/m', $titleBlock, $found) === 1 && $found[1] !== $page->setting('title')) {
            $changes['title'] = $found[1];
        }
        if ($page->kind === 'guide' || $page->kind === 'guides') {
            // the subtitle is the line after the title
            $lines = [];
            foreach (explode("\n", $titleBlock) as $line) {
                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }
            if (isset($lines[1]) && !str_starts_with($lines[1], '#') && $lines[1] !== $page->setting('subtitle')) {
                $changes['subtitle'] = $lines[1];
            }
        }

        if (count($changes) > 0) {
            foreach ($changes as $name => $value) {
                $page->meta[$name] = $value;
            }
            $page->yaml = rtrim(Yaml::dump($page->meta, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }
    }

    private function markdownFiles(): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if (str_ends_with($item->getFilename(), '.md')) {
                $files[] = $item->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
