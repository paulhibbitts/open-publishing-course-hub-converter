<?php
namespace OPG;

// Writes guides as an Open Publishing Space site's pages folder: the Guides page, numbered guide folders with
// their section-list.md, numbered section folders with section.md, numbered page folders with their page, and
// each page's images beside it
class GravWriter
{
    public function write(Site $site, string $out): void
    {
        if ($site->guidesPage !== null) {
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
            foreach ($guide->menuPages as $menuPage) {
                $this->writePage($menuPage, $out);
            }
        }
    }

    private function writePage(Page $page, string $out): void
    {
        $folder = $out . '/' . $page->folder;
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $text = "---\n" . rtrim($page->yaml) . "\n---\n\n" . trim($page->body) . "\n";
        file_put_contents($folder . '/' . $page->file, $text);

        foreach ($page->images as $image) {
            if (isset($page->imageSources[$image]) && is_file($page->imageSources[$image])) {
                copy($page->imageSources[$image], $folder . '/' . $image);
            }
        }
    }
}
