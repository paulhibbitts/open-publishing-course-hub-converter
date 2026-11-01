<?php
namespace OPG;

// One page of an Open Publishing Space site: the Guides page, a guide home, a section, a reading page,
// or a guide menu page (such as Topics). Both directions read into this model and write from it.
class Page
{
    public string $kind;          // 'guides', 'guide', 'section', 'page' or 'menu'
    public string $folder;        // the page's folder, from the pages folder, e.g. '01.open-education-essentials/01.what-is-open-education'
    public string $file;          // its Markdown file, e.g. 'section.md' (the file name is Grav's page type)
    public string $yaml = '';     // the front matter exactly as written, so a round trip can give it back unchanged
    public array $meta = [];      // the same front matter, parsed
    public string $body = '';     // the Markdown below the front matter
    public array $images = [];    // image files in the page's own folder
    public array $children = [];  // sections of a guide, or pages of a section
    public array $menuPages = []; // a guide's unnumbered pages, such as Topics and Resources

    public function __construct(string $kind, string $folder, string $file)
    {
        $this->kind = $kind;
        $this->folder = $folder;
        $this->file = $file;
    }

    // The folder name without Grav's ordering number: '01.what-is-open-education' → 'what-is-open-education'
    public function slug(): string
    {
        return Helpers::slugFromFolder(basename($this->folder));
    }

    public function title(): string
    {
        return (string)($this->meta['title'] ?? Helpers::titleFromSlug($this->slug()));
    }
}
