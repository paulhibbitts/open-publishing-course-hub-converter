<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads a Docsify guide site (laid out like the Docsify-This guide templates) back into guides. Pages written by
// this converter carry a hidden note with their original Grav file and settings, so they go back unchanged.
class DocsifyReader
{
    private string $dir;
    private string $siteUrl = '';
    // the single guide's address name, or '' for a site with a Guides page
    private string $single = '';

    public function read(string $dir): Site
    {
        $this->dir = rtrim($dir, '/');
        $pages = [];
        foreach ($this->markdownFiles() as $path) {
            $note = Helpers::readGravNote(file_get_contents($path));
            if ($note !== null) {
                $pages[] = [$path, $note];
            }
        }
        // the original site's address (if one was given) is kept with the home page
        foreach ($pages as [$path, [$where]]) {
            if (!empty($where['site'])) {
                $this->siteUrl = $where['site'];
            }
        }
        // a single-guide site's home.md is its guide home
        foreach ($pages as [$path, [$where]]) {
            if (basename($path) === 'home.md' && str_ends_with($where['file'], 'section-list.md')) {
                $this->single = Helpers::slugFromFolder(basename(dirname($where['file'])));
            }
        }
        return $this->fromNotes($pages);
    }

    // Rebuilds the guides from the hidden notes: each note says where its page goes in Grav
    private function fromNotes(array $notes): Site
    {
        $site = new Site();
        $byFolder = [];
        foreach ($notes as [$path, [$where, $yaml, $rest]]) {
            $file = basename($where['file']);
            $folder = dirname($where['file']);
            $depth = count(explode('/', $folder));
            $kind = match (true) {
                $file === 'reader-list.md' => 'guides',
                $file === 'section-list.md' => 'guide',
                $depth === 2 && !Helpers::isNumbered(basename($folder)) => 'menu',
                $depth === 2 => 'section',
                default => 'page',
            };
            $page = new Page($kind, $folder, $file);
            $page->yaml = $yaml;
            $page->meta = (array)(Yaml::parse($yaml) ?? []);
            $page->body = $this->body($kind, $rest);
            $this->updateVisibleSettings($page, $rest);
            foreach ($where['images'] ?? [] as $image) {
                $page->images[] = $image;
                // images are beside the Docsify page (a page's images are in its section's folder)
                $page->imageSources[$image] = dirname($path) . '/' . $image;
            }
            $byFolder[$folder] = $page;
        }
        ksort($byFolder, SORT_NATURAL);
        foreach ($byFolder as $folder => $page) {
            $parent = $byFolder[dirname($folder)] ?? null;
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

    // The author's text: everything the converter didn't add, with callouts and links back in Grav's form.
    // A guide home's (and the Guides page's) text is split by the section cards: the part below them was below ===.
    private function body(string $kind, string $rest): string
    {
        $blocks = preg_split('/<!-- grav:gen -->\n.*?\n<!-- \/grav:gen -->/s', $rest);
        $blocks = array_map('trim', $blocks);
        if ($kind === 'guide' || $kind === 'guides') {
            // [before the title, intro, after the cards (maybe === and more text), after the footer]
            $text = $blocks[1] ?? '';
            $after = $blocks[2] ?? '';
            if (str_starts_with($after, '<!-- grav:=== -->')) {
                $text .= "\n\n===\n\n" . trim(substr($after, strlen('<!-- grav:=== -->')));
            }
        } else {
            $text = implode("\n\n", array_filter(array_slice($blocks, 1), 'strlen'));
        }
        $text = Callouts::toGrav($text);
        return Links::toGrav($text, $this->single, $this->siteUrl);
    }

    // A title or subtitle changed in Docsify goes into the page's settings (the rest of the front matter is
    // kept exactly as it was)
    private function updateVisibleSettings(Page $page, string $rest): void
    {
        if (!preg_match('/<!-- grav:gen -->\n(.*?)\n<!-- \/grav:gen -->/s', $rest, $m)) {
            return;
        }
        $changes = [];
        if (preg_match('/^# (.+)$/m', $m[1], $t) && $t[1] !== (string)($page->meta['title'] ?? '')) {
            $changes['title'] = $t[1];
        }
        if ($page->kind === 'guide' || $page->kind === 'guides') {
            $lines = array_values(array_filter(explode("\n", $m[1]), fn($l) => trim($l) !== ''));
            $subtitle = isset($lines[1]) && !str_starts_with($lines[1], '#') ? $lines[1] : '';
            if ($subtitle !== '' && $subtitle !== (string)($page->meta['subtitle'] ?? '')) {
                $changes['subtitle'] = $subtitle;
            }
        }
        if ($changes) {
            $page->meta = array_merge($page->meta, $changes);
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
