<?php
namespace OPG;

// Writes guides as an Open Publishing Space site's pages folder: the Guides page, numbered guide folders with
// their section-list.md, numbered section folders with section.md, numbered page folders with their page, and
// each page's images beside it
class GravWriter
{
    public function write(Site $site, string $out): void
    {
        if ($site->guidesPage) {
            $this->writePage($site->guidesPage, $out);
        }
        foreach ($site->guides as $guide) {
            $this->writePage($guide, $out);
            foreach ($guide->children as $section) {
                $this->writePage($section, $out);
                foreach ($section->children as $page) {
                    $this->writePage($page, $out);
                }
            }
            foreach ($guide->menuPages as $page) {
                $this->writePage($page, $out);
            }
        }
    }

    private function writePage(Page $page, string $out): void
    {
        $dir = $out . '/' . $page->folder;
        @mkdir($dir, 0777, true);
        $text = "---\n" . rtrim($page->yaml) . "\n---\n\n" . trim($page->body) . "\n";
        file_put_contents($dir . '/' . $page->file, $text);
        foreach ($page->images as $image) {
            $source = $page->imageSources[$image] ?? null;
            if ($source !== null && is_file($source)) {
                copy($source, $dir . '/' . $image);
            }
        }
    }
}
