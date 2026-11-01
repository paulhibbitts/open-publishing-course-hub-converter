<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads the guides of an Open Publishing Space site from its user/pages folder
class GravReader
{
    public function read(string $pagesDir): Site
    {
        $site = new Site();
        foreach ($this->folders($pagesDir) as $name) {
            $dir = $pagesDir . '/' . $name;
            if (is_file($dir . '/reader-list.md')) {
                $site->guidesPage = $this->readPage($pagesDir, $name, 'reader-list.md', 'guides');
            } elseif (is_file($dir . '/section-list.md')) {
                $site->guides[] = $this->readGuide($pagesDir, $name);
            }
            // anything else (the blog, standard and modular pages) isn't a guide, so it isn't converted
        }
        return $site;
    }

    private function readGuide(string $pagesDir, string $folder): Page
    {
        $guide = $this->readPage($pagesDir, $folder, 'section-list.md', 'guide');
        foreach ($this->folders($pagesDir . '/' . $folder) as $name) {
            $file = $this->markdownFile($pagesDir . '/' . $folder . '/' . $name);
            if ($file === null) {
                continue;
            }
            if (!Helpers::isNumbered($name)) {
                // a guide menu page, such as Topics or Resources
                $guide->menuPages[] = $this->readPage($pagesDir, $folder . '/' . $name, $file, 'menu');
                continue;
            }
            $section = $this->readPage($pagesDir, $folder . '/' . $name, $file, 'section');
            foreach ($this->folders($pagesDir . '/' . $section->folder) as $pageName) {
                $pageFile = $this->markdownFile($pagesDir . '/' . $section->folder . '/' . $pageName);
                if ($pageFile !== null && Helpers::isNumbered($pageName)) {
                    $section->children[] = $this->readPage($pagesDir, $section->folder . '/' . $pageName, $pageFile, 'page');
                }
            }
            $guide->children[] = $section;
        }
        return $guide;
    }

    private function readPage(string $pagesDir, string $folder, string $file, string $kind): Page
    {
        $page = new Page($kind, $folder, $file);
        $text = str_replace("\r\n", "\n", file_get_contents($pagesDir . '/' . $folder . '/' . $file));
        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $m)) {
            $page->yaml = $m[1];
            $page->meta = (array)(Yaml::parse($m[1]) ?? []);
            $page->body = $m[2];
        } else {
            $page->body = $text;
        }
        foreach (scandir($pagesDir . '/' . $folder) as $name) {
            if (Helpers::isImage($name)) {
                $page->images[] = $name;
            }
        }
        return $page;
    }

    // Sub-folders, in Grav's order (ordering numbers first, then by name)
    private function folders(string $dir): array
    {
        $names = array_filter(scandir($dir), fn($n) => $n[0] !== '.' && is_dir($dir . '/' . $n));
        sort($names, SORT_NATURAL);
        return array_values($names);
    }

    private function markdownFile(string $dir): ?string
    {
        $files = array_values(array_filter(scandir($dir), fn($n) => str_ends_with($n, '.md')));
        return $files[0] ?? null;
    }
}
