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

    // A page's file (its front matter and text) and its images, in its folder under $out (also used for courses)
    public function writePage(Page $page, string $out): void
    {
        $folder = $out . '/' . $page->folder;
        if (!is_dir($folder)) {
            mkdir($folder, 0700, true);
        }
        $text = "---\n" . rtrim($page->yaml) . "\n---\n" . $page->textBefore . trim($page->body) . $page->textAfter;
        file_put_contents($folder . '/' . $page->file, $text);

        foreach ($page->images as $image) {
            if (isset($page->imageSources[$image]) && is_file($page->imageSources[$image])) {
                copy($page->imageSources[$image], $folder . '/' . $image);
            }
        }
    }
}
