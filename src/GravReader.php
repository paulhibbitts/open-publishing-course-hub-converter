<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads the guides of an Open Publishing Space site from its user/pages folder
class GravReader
{
    public function read(string $pagesDir): Site
    {
        $site = new Site();
        foreach ($this->subfolders($pagesDir) as $name) {
            $folder = $pagesDir . '/' . $name;
            if (is_file($folder . '/reader-list.md')) {
                $site->guidesPage = $this->readPage($pagesDir, $name, 'reader-list.md', 'guides');
            } elseif (is_file($folder . '/section-list.md')) {
                $site->guides[] = $this->readGuide($pagesDir, $name);
            }
            // anything else (the blog, standard and modular pages) isn't a guide, so it isn't converted
        }
        return $site;
    }

    // A guide: its numbered folders are its sections (each with numbered page folders); its unnumbered
    // folders are menu pages such as Topics and Resources
    private function readGuide(string $pagesDir, string $guideFolder): Page
    {
        $guide = $this->readPage($pagesDir, $guideFolder, 'section-list.md', 'guide');
        foreach ($this->subfolders($pagesDir . '/' . $guideFolder) as $name) {
            $folder = $guideFolder . '/' . $name;
            $file = $this->markdownFile($pagesDir . '/' . $folder);
            if ($file === '') {
                continue;
            }
            if (!Helpers::isNumbered($name)) {
                $guide->menuPages[] = $this->readPage($pagesDir, $folder, $file, 'menu');
                continue;
            }
            $section = $this->readPage($pagesDir, $folder, $file, 'section');
            foreach ($this->subfolders($pagesDir . '/' . $folder) as $pageName) {
                $pageFolder = $folder . '/' . $pageName;
                $pageFile = $this->markdownFile($pagesDir . '/' . $pageFolder);
                if ($pageFile !== '' && Helpers::isNumbered($pageName)) {
                    $section->children[] = $this->readPage($pagesDir, $pageFolder, $pageFile, 'page');
                }
            }
            $guide->children[] = $section;
        }
        return $guide;
    }

    private function readPage(string $pagesDir, string $folder, string $file, string $kind): Page
    {
        $page = new Page($kind, $folder, $file);
        $text = file_get_contents($pagesDir . '/' . $folder . '/' . $file);
        $text = str_replace("\r\n", "\n", $text);

        // front matter: the settings between the two --- lines at the top
        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $match) === 1) {
            $page->yaml = $match[1];
            $settings = Yaml::parse($match[1]);
            if (is_array($settings)) {
                $page->meta = $settings;
            }
            $page->body = $match[2];
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

    // Sub-folders in Grav's order (by their ordering numbers, then by name)
    private function subfolders(string $dir): array
    {
        $names = [];
        foreach (scandir($dir) as $name) {
            $isHidden = str_starts_with($name, '.');
            if (!$isHidden && is_dir($dir . '/' . $name)) {
                $names[] = $name;
            }
        }
        // SORT_NATURAL puts 2. before 10.
        sort($names, SORT_NATURAL);
        return $names;
    }

    // The folder's Markdown file (a Grav page has one), or '' when there isn't one
    private function markdownFile(string $dir): string
    {
        foreach (scandir($dir) as $name) {
            if (str_ends_with($name, '.md')) {
                return $name;
            }
        }
        return '';
    }
}
