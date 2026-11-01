<?php
namespace OPG;

// Writes guides as a Docsify guide site, laid out like the Docsify-This Multiple Guides Site and Guide Site
// templates. The same Markdown pages work in a repo shown by Docsify-This and in a Starter Kit's docs/ folder.
class DocsifyWriter
{
    // A page outside the reading order hides the sidebar with this line, at the end of the page
    public const HIDE_SIDEBAR = '<style>aside.sidebar, button.sidebar-toggle { display: none !important; } section.content { left: 0 !important; transition: none !important; }</style>';

    private string $pagesDir;
    private string $out;
    private bool $single;
    private array $guideSlugs = [];
    // a single guide's menu pages, for the site's navbar
    private array $menuLinks = [];
    private string $siteUrl = '';
    // links that only work on the Grav site, and anything else worth checking (for conversion-notes.txt)
    public array $warnings = [];

    // $single: a single-guide site (the guide home is home.md, sections are top-level folders, no Guides page)
    // $siteUrl: the original Grav site's address, for links to its other pages (optional)
    public function write(Site $site, string $pagesDir, string $out, bool $single = false, string $siteUrl = ''): void
    {
        $this->pagesDir = $pagesDir;
        $this->out = $out;
        $this->single = $single;
        $this->siteUrl = $siteUrl;
        $this->warnings = [];
        $this->menuLinks = [];

        $guides = $single ? array_slice($site->guides, 0, 1) : $site->guides;
        $this->guideSlugs = array_map(fn(Page $g) => $g->slug(), $guides);
        $cards = [];
        foreach ($guides as $guide) {
            $cards = array_merge($cards, $this->writeGuide($guide));
        }
        if (!$single) {
            $this->writeGuidesPage($site->guidesPage, $cards);
        }
        // an empty navbar: on the Guides page it would only link to itself (each guide has its own); a Starter Kit
        // loads one, and an empty one takes no space (Docsify-This only loads it with loadNavbar)
        $this->writeFile('_navbar.md', implode("\n", $this->menuLinks));
    }

    // Writes one guide and returns its card for the Guides page
    private function writeGuide(Page $guide): array
    {
        $g = $guide->slug();
        $top = $this->single ? '' : $g . '/';
        $homeFile = $this->single ? 'home.md' : $g . '/README.md';
        $label = (string)($guide->meta['section_label'] ?? 'Section');
        $title = $guide->title();
        // every reading page links back to its guide home, except in a single guide, where the site's name
        // (at the top of the sidebar) is the guide's name and already links home
        $back = $this->single ? [] : ['[← ' . $title . '](' . $homeFile . ')', ''];

        if (!$this->single) {
            // (in a single guide, the guide folder's only image is its cover, which only the Guides page shows)
            $this->copyImages($guide, $g);
        }

        $sections = [];
        foreach ($guide->children as $i => $section) {
            $number = (string)($section->meta['section_number'] ?? ($i + 1));
            $sections[] = [$section, $number];
            $this->copyImages($section, $top . $section->slug());
            foreach ($section->children as $page) {
                // a page's images go in its section's folder, beside the page
                $this->copyImages($page, $top . $section->slug());
            }

            $head = array_merge($back, ['# ' . $section->title(), '', '<small>' . $label . ' ' . $number . '</small>']);
            $sectionPath = $top . $section->slug() . '/README.md';
            $body = $this->convert($section->body, '/' . $g . '/' . $section->slug(), $sectionPath);
            if (!empty($section->meta['learning_objectives'])) {
                $head = array_merge($head, ['', Callouts::quote('TIP', 'Learning Objectives', (string)$section->meta['learning_objectives'])]);
                $body = Callouts::tipsToNotes($body);
            }
            $this->writePage($sectionPath, $section, $head, $body);

            foreach ($section->children as $page) {
                $head = array_merge($back, ['# ' . $page->title()]);
                $pagePath = $top . $section->slug() . '/' . $page->slug() . '.md';
                $this->writePage($pagePath, $page, $head, $this->convert($page->body, '/' . $g . '/' . $section->slug() . '/' . $page->slug(), $pagePath));
            }
        }

        // guide menu pages (Topics, Resources …): outside the reading order, so they hide the sidebar, link back
        // to the guide home (even in a single guide, since the site's name is hidden with the sidebar), and are
        // linked from the guide's navbar, in Grav's menu_order
        $menuPages = $guide->menuPages;
        usort($menuPages, fn(Page $a, Page $b) => ((int)($a->meta['menu_order'] ?? 99)) <=> ((int)($b->meta['menu_order'] ?? 99)));
        $menuLinks = [];
        foreach ($menuPages as $page) {
            $path = $top . $page->slug() . '.md';
            $body = trim($this->convert($page->body, '/' . $g . '/' . $page->slug(), $path));
            // the page's own text often starts with its title; if not, it's added
            $head = ['[← ' . $title . '](' . $homeFile . ')'];
            if (!preg_match('/^# /', $body)) {
                $head = array_merge($head, ['', '# ' . $page->title()]);
            }
            $lines = array_merge([Helpers::gravNote($page), ''], Helpers::generated($head), ['', $body, ''],
                Helpers::generated(['<!-- Hides the sidebar on this page -->', self::HIDE_SIDEBAR]));
            $this->writeFile($path, implode("\n", $lines));
            $menuLinks[] = '- [' . $page->title() . '](' . $path . ')';
        }

        $this->writeGuideHome($guide, $homeFile, $top, $label, $sections);
        $this->writeSidebar($guide, $top, $sections);
        if (!$this->single) {
            $this->writeFile($g . '/_navbar.md', implode("\n", array_merge(['- [All Guides](home.md)'], $menuLinks)));
        } elseif ($menuLinks) {
            $this->menuLinks = $menuLinks;
        }
        return $this->guideCard($guide);
    }

    // The guide's home page: title and subtitle, its intro, Start Reading and a card for each section, the rest
    // of its text (below === in Grav), then the Side Box, authors line and attribution
    private function writeGuideHome(Page $guide, string $homeFile, string $top, string $label, array $sections): void
    {
        $g = $guide->slug();
        [$above, $below] = $this->splitAtSeparator($guide->body);

        $cards = [];
        foreach ($sections as [$section, $number]) {
            $card = ['<div class="card">', ''];
            if (!empty($section->meta['image'])) {
                // (images are read from the page's own folder, links from the site's top)
                $card[] = '[![' . $section->title() . '](' . $section->slug() . '/' . $section->meta['image'] . ' ":no-zoom")](' . $top . $section->slug() . '/README.md)';
                $card[] = '';
            }
            $card = array_merge($card, [$label . ' ' . $number, '', '<h2><a href="#/' . $top . $section->slug() . '/README">' . $section->title() . '</a></h2>', '']);
            if (!empty($section->meta['description'])) {
                $card = array_merge($card, [(string)$section->meta['description'], '']);
            }
            $cards = array_merge($cards, $card, ['</div>', '']);
        }
        $first = $sections ? $top . $sections[0][0]->slug() . '/README.md' : $homeFile;

        // (a single guide's home is the site's home page, so it also keeps the original site's address)
        $lines = [Helpers::gravNote($guide, $this->single && $this->siteUrl !== '' ? ['site' => $this->siteUrl] : []), ''];
        $lines = array_merge($lines, Helpers::generated(['# ' . $guide->title(), '', (string)($guide->meta['subtitle'] ?? '')]), ['']);
        $lines[] = trim($this->convert($above, '/' . $g, $homeFile));
        $lines[] = '';
        $lines = array_merge($lines, Helpers::generated(array_merge(
            ['[Start Reading](' . $first . ' ":class=button :class=primary")', '', '<div class="card-list-sections">', ''],
            $cards,
            ['</div>']
        )), ['']);
        if (trim($below) !== '') {
            $lines[] = '<!-- grav:=== -->';
            $lines[] = '';
            $lines[] = trim($this->convert($below, '/' . $g, $homeFile));
            $lines[] = '';
        }
        $foot = [];
        if (!empty($guide->meta['side_box'])) {
            // the Side Box invites readers to act, so a TIP
            $foot[] = "> [!TIP]\n" . implode("\n", array_map(fn($l) => $l === '' ? '>' : '> ' . $l, explode("\n", trim($this->convert((string)$guide->meta['side_box'], '/' . $g, $homeFile)))));
            $foot[] = '';
        }
        $foot[] = (string)($guide->meta['authors'] ?? '') . ' · ' . (string)($guide->meta['edition'] ?? '') . $this->updated($guide);
        if (!empty($guide->meta['attribution_text'])) {
            $foot = array_merge($foot, ['', (string)$guide->meta['attribution_text']]);
        }
        $foot = array_merge($foot, ['', '<!-- Hides the sidebar on this page -->', self::HIDE_SIDEBAR]);
        $lines = array_merge($lines, Helpers::generated($foot));
        $this->writeFile($homeFile, implode("\n", $lines));
    }

    // The reading order: a group for each section with its pages (the guide home isn't listed: it hides the
    // sidebar, and every page leads back to it). Docsify can open and close a group only when it's at the top
    // level with a plain-text title, so the section's own page is the group's first link, as Overview.
    private function writeSidebar(Page $guide, string $top, array $sections): void
    {
        // the guide's name first, in bold (not a link, so not a page); not needed in a single guide, whose
        // site name is the guide's name
        $lines = $this->single ? [] : ['**' . $guide->title() . '**', ''];
        foreach ($sections as [$section]) {
            $link = $top . $section->slug() . '/README.md';
            if (!$section->children) {
                $lines[] = '- [' . $section->title() . '](' . $link . ')';
                continue;
            }
            $lines[] = '- ' . $section->title();
            $lines[] = '  - [Overview](' . $link . ')';
            foreach ($section->children as $page) {
                $lines[] = '  - [' . $page->title() . '](' . $top . $section->slug() . '/' . $page->slug() . '.md)';
            }
        }
        $this->writeFile($top . '_sidebar.md', implode("\n", $lines));
    }

    private function guideCard(Page $guide): array
    {
        $g = $guide->slug();
        $card = ['<div class="card">', ''];
        if (!empty($guide->meta['cover_image'])) {
            $alt = (string)($guide->meta['cover_image_alt'] ?? $guide->title());
            $card = array_merge($card, ['[![' . $alt . '](' . $g . '/' . $guide->meta['cover_image'] . ' ":no-zoom")](' . $g . '/README.md)', '']);
        }
        // a badge such as "Draft" goes inside the title, so the cards still line up
        $badge = !empty($guide->meta['badge_label']) ? ' <span class="badge">' . $guide->meta['badge_label'] . '</span>' : '';
        return array_merge($card, [
            '<h2><a href="#/' . $g . '/README">' . $guide->title() . '</a>' . $badge . '</h2>', '',
            (string)($guide->meta['subtitle'] ?? ''), '',
            '<small>' . ($guide->meta['authors'] ?? '') . '<br>' . ($guide->meta['edition'] ?? '') . $this->updated($guide) . '</small>', '',
            '</div>', '',
        ]);
    }

    // The Guides page (Grav's Guides List page), with a card for each guide
    private function writeGuidesPage(?Page $page, array $cards): void
    {
        $page ??= new Page('guides', '00.guides', 'reader-list.md');
        [$above, $below] = $this->splitAtSeparator($page->body);
        // the original site's address is kept with the home page, so links to it can go back to Grav's own form
        $lines = [Helpers::gravNote($page, $this->siteUrl !== '' ? ['site' => $this->siteUrl] : []), ''];
        $lines = array_merge($lines, Helpers::generated(['# ' . ($page->meta['title'] ?? 'Guides'), '', (string)($page->meta['subtitle'] ?? '')]), ['']);
        $lines[] = trim($this->convert($above, '/' . $page->slug(), 'home.md'));
        $lines[] = '';
        // search lives in each guide's sidebar, which the Guides page and guide homes hide
        $lines = array_merge($lines, Helpers::generated(array_merge(
            ['Each guide has its own search box, on its reading pages.', '', '<div class="card-list">', ''],
            $cards,
            ['</div>']
        )), ['']);
        if (trim($below) !== '') {
            $lines = array_merge($lines, ['<!-- grav:=== -->', '', trim($this->convert($below, '/' . $page->slug(), 'home.md')), '']);
        }
        $lines = array_merge($lines, Helpers::generated(['<!-- Hides the sidebar on this page -->', self::HIDE_SIDEBAR]));
        $this->writeFile('home.md', implode("\n", $lines));
    }

    private function writePage(string $path, Page $page, array $head, string $body): void
    {
        $lines = array_merge([Helpers::gravNote($page), ''], Helpers::generated($head), ['', trim($body)]);
        $this->writeFile($path, implode("\n", $lines));
    }

    // $gravPath: the page's address on the Grav site; $where: the Docsify file, for warnings
    private function convert(string $markdown, string $gravPath, string $where): string
    {
        $markdown = Links::toDocsify(Callouts::toDocsify($markdown), $this->guideSlugs, $this->single);
        foreach (Callouts::unknownShortcodes($markdown) as $name) {
            $this->warnings[] = $where . ': the [' . $name . '] shortcode only works in Grav (left as it is)';
        }
        return Links::gravOnly($markdown, $gravPath, $this->siteUrl, $where, $this->warnings);
    }

    // A guide home's text is split by a line of === : the intro above the section cards, the rest below them
    private function splitAtSeparator(string $body): array
    {
        $parts = preg_split('/^===[ \t]*$/m', $body, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    private function updated(Page $guide): string
    {
        return empty($guide->meta['last_updated']) ? '' : ' · Updated ' . Helpers::shortDate((string)$guide->meta['last_updated']);
    }

    private function copyImages(Page $page, string $toFolder): void
    {
        foreach ($page->images as $image) {
            $target = $this->out . '/' . ltrim($toFolder . '/' . $image, '/');
            @mkdir(dirname($target), 0777, true);
            copy($this->pagesDir . '/' . $page->folder . '/' . $image, $target);
        }
    }

    private function writeFile(string $path, string $text): void
    {
        $target = $this->out . '/' . $path;
        @mkdir(dirname($target), 0777, true);
        file_put_contents($target, rtrim($text) . "\n");
    }
}
