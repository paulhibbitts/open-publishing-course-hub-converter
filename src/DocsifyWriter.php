<?php
namespace OPG;

// Writes guides as a Docsify guide site, laid out like the Docsify-This Multiple Guides Site and Guide Site
// templates. The same Markdown pages work in a repo shown by Docsify-This and in a Starter Kit's docs/ folder.
class DocsifyWriter
{
    // A page outside the reading order hides the sidebar with this line, at the end of the page
    public const HIDE_SIDEBAR = '<style>aside.sidebar, button.sidebar-toggle { display: none !important; } section.content { left: 0 !important; transition: none !important; }</style>';

    // Links that only work on the Grav site, and anything else worth checking (for conversion-notes.txt)
    public array $warnings = [];

    private string $pagesDir;
    private string $out;
    private bool $single;
    private string $siteUrl;
    private array $guideSlugs = [];
    // a single guide's menu pages (Topics, Resources …), linked from the site's navbar
    private array $siteMenuLinks = [];

    // $single: a single-guide site (the guide home is home.md, sections are top-level folders, no Guides page)
    // $siteUrl: the original Grav site's address, for links to its other pages (optional)
    public function write(Site $site, string $pagesDir, string $out, bool $single = false, string $siteUrl = ''): void
    {
        $this->pagesDir = $pagesDir;
        $this->out = $out;
        $this->single = $single;
        $this->siteUrl = $siteUrl;
        $this->warnings = [];
        $this->siteMenuLinks = [];

        $guides = $site->guides;
        if ($single) {
            // a single-guide site: the first guide
            $guides = array_slice($site->guides, 0, 1);
        }
        $this->guideSlugs = [];
        foreach ($guides as $guide) {
            $this->guideSlugs[] = $guide->slug();
        }

        $cards = [];
        foreach ($guides as $guide) {
            $card = $this->writeGuide($guide);
            $cards = array_merge($cards, $card);
        }
        if (!$single) {
            $this->writeGuidesPage($site->guidesPage, $cards);
            // a sidebar file with only a comment: Docsify looks for one beside the Guides page (no 404), and shows no sidebar
            $this->writeFile('_sidebar.md', '<!-- No sidebar on the Guides page: each guide folder has its own _sidebar.md -->');
        }
        // The top navbar: empty on the Guides page (each guide has its own), or a single guide's menu pages.
        // A Starter Kit always loads it, and an empty one takes no space; Docsify-This only loads it with loadNavbar.
        $this->writeFile('_navbar.md', implode("\n", $this->siteMenuLinks));
    }

    // Writes one guide and returns its card for the Guides page
    private function writeGuide(Page $guide): array
    {
        $guideSlug = $guide->slug();
        // where the guide's files go, and its home page
        $top = $guideSlug . '/';
        $homeFile = $guideSlug . '/README.md';
        if ($this->single) {
            $top = '';
            $homeFile = 'home.md';
        }
        $label = $guide->setting('section_label');
        if ($label === '') {
            $label = 'Section';
        }

        // every reading page links back to its guide home, except in a single guide, where the site's name
        // (at the top of the sidebar) is the guide's name and already links home
        $backLink = [];
        if (!$this->single) {
            $backLink = ['[← ' . $guide->title() . '](' . $homeFile . ')', ''];
            $this->copyImages($guide, $guideSlug);
        } else {
            // a single guide's own folder only holds its cover, shown on its home page (home.md, at the top)
            $this->copyImages($guide, '');
        }

        $sections = [];
        $sectionNumber = 0;
        foreach ($guide->children as $section) {
            $sectionNumber++;
            $number = $section->setting('section_number');
            if ($number === '') {
                $number = (string)$sectionNumber;
            }
            $sections[] = ['page' => $section, 'number' => $number];
            $this->writeSection($section, $guideSlug, $top, $label, $number, $backLink);
        }

        $menuLinks = $this->writeMenuPages($guide, $top, $homeFile);
        $this->writeGuideHome($guide, $homeFile, $top, $label, $sections);
        $this->writeSidebar($guide, $top, $sections);
        if ($this->single) {
            $this->siteMenuLinks = $menuLinks;
        } else {
            $navbar = array_merge(['- [All Guides](home.md)'], $menuLinks);
            $this->writeFile($guideSlug . '/_navbar.md', implode("\n", $navbar));
        }
        return $this->guideCard($guide);
    }

    // A section's page (its title, section label, Learning Objectives and text), then each of its pages
    private function writeSection(Page $section, string $guideSlug, string $top, string $label, string $number, array $backLink): void
    {
        $sectionFolder = $top . $section->slug();
        $this->copyImages($section, $sectionFolder);
        foreach ($section->children as $page) {
            // a page's images go in its section's folder, beside the page
            $this->copyImages($page, $sectionFolder);
        }

        $sectionFile = $sectionFolder . '/README.md';
        $head = array_merge($backLink, ['# ' . $section->title(), '', '<small>' . $label . ' ' . $number . '</small>']);
        $body = $this->convert($section->body, '/' . $guideSlug . '/' . $section->slug(), $sectionFile);
        $objectives = $section->setting('learning_objectives');
        if ($objectives !== '') {
            $head[] = '';
            $head[] = Callouts::quote('TIP', 'Learning Objectives', $objectives);
            $body = Callouts::tipsToNotes($body);
        }
        $gravPath = '/' . $guideSlug . '/' . $section->slug();
        $this->writePage($sectionFile, $section, $head, $body, $this->sideBox($section, $gravPath, $sectionFile));

        foreach ($section->children as $page) {
            $pageFile = $sectionFolder . '/' . $page->slug() . '.md';
            $gravPath = '/' . $guideSlug . '/' . $section->slug() . '/' . $page->slug();
            $head = array_merge($backLink, ['# ' . $page->title()]);
            $body = $this->convert($page->body, $gravPath, $pageFile);
            $this->writePage($pageFile, $page, $head, $body, $this->sideBox($page, $gravPath, $pageFile));
        }
    }

    // A section's or page's Side Box (shown beside its text in Grav) as a NOTE at the end of the page, as on the
    // hand-built Read, Move, Reuse site; [] when it has none
    private function sideBox(Page $page, string $gravPath, string $where): array
    {
        $sideBox = $page->setting('side_box');
        if ($sideBox === '') {
            return [];
        }
        return ["> [!NOTE]\n" . Helpers::quoteLines(trim($this->convert($sideBox, $gravPath, $where)))];
    }

    // Guide menu pages (Topics, Resources …) are outside the reading order: they hide the sidebar, link back to
    // the guide home (even in a single guide, since the site's name is hidden with the sidebar), and are linked
    // from the navbar, in Grav's menu_order. Returns those navbar links.
    private function writeMenuPages(Page $guide, string $top, string $homeFile): array
    {
        $menuPages = $guide->menuPages;
        usort($menuPages, [$this, 'compareMenuOrder']);

        $navbarLinks = [];
        foreach ($menuPages as $page) {
            $path = $top . $page->slug() . '.md';
            $body = trim($this->convert($page->body, '/' . $guide->slug() . '/' . $page->slug(), $path));
            $head = ['[← ' . $guide->title() . '](' . $homeFile . ')'];
            // the page's own text often starts with its title; if not, it's added
            if (!str_starts_with($body, '# ')) {
                $head[] = '';
                $head[] = '# ' . $page->title();
            }
            $hideSidebar = ['<!-- Hides the sidebar on this page -->', self::HIDE_SIDEBAR];
            $lines = array_merge([Helpers::gravNote($page), ''], Helpers::generated($head), ['', $body, ''], Helpers::generated($hideSidebar));
            $this->writeFile($path, implode("\n", $lines));
            $navbarLinks[] = '- [' . $page->title() . '](' . $path . ')';
        }
        return $navbarLinks;
    }

    private function compareMenuOrder(Page $a, Page $b): int
    {
        return $this->menuOrder($a) <=> $this->menuOrder($b);
    }

    // Grav's menu_order setting; pages without one come last
    private function menuOrder(Page $page): int
    {
        if (!isset($page->meta['menu_order'])) {
            return 99;
        }
        return (int)$page->meta['menu_order'];
    }

    // The guide's home page: title and subtitle, its intro, Start Reading and a card for each section, the rest
    // of its text (below === in Grav), then the Side Box, authors line and attribution
    private function writeGuideHome(Page $guide, string $homeFile, string $top, string $label, array $sections): void
    {
        $gravPath = '/' . $guide->slug();
        $textParts = $this->splitAtSeparator($guide->body);

        $cards = [];
        foreach ($sections as $item) {
            $cards = array_merge($cards, $this->sectionCard($item['page'], $item['number'], $top, $label));
        }
        $firstPage = $homeFile;
        if (count($sections) > 0) {
            $firstPage = $top . $sections[0]['page']->slug() . '/README.md';
        }

        // a single guide's home is the site's home page, so it also keeps the original site's address
        $noteExtra = [];
        if ($this->single && $this->siteUrl !== '') {
            $noteExtra = ['site' => $this->siteUrl];
        }
        $startReading = '[Start Reading](' . $firstPage . ' ":class=button :class=primary")';
        // with a small cover (Grav's default), the cover, details and Start Reading go at the top, as in Grav
        $smallCover = $guide->setting('cover_image') !== '' && $guide->setting('cover_image_layout') !== 'large';

        $lines = [Helpers::gravNote($guide, $noteExtra), ''];
        if ($smallCover) {
            $lines = array_merge($lines, Helpers::generated($this->guideCover($guide, $startReading)), ['']);
        } else {
            $head = ['# ' . $guide->title(), '', $guide->setting('subtitle')];
            if ($guide->setting('cover_image') !== '') {
                // a large cover goes across the page, above the title
                $head = array_merge([$this->coverImage($guide), ''], $head);
            }
            $lines = array_merge($lines, Helpers::generated($head), ['']);
        }
        $lines[] = trim($this->convert($textParts['above'], $gravPath, $homeFile));
        $lines[] = '';

        $startAndCards = ['<div class="card-list-sections">', ''];
        if (!$smallCover) {
            $startAndCards = array_merge([$startReading, ''], $startAndCards);
        }
        $startAndCards = array_merge($startAndCards, $cards, ['</div>']);
        $lines = array_merge($lines, Helpers::generated($startAndCards), ['']);

        if (trim($textParts['below']) !== '') {
            // marks where Grav's === line was, for the way back
            $lines[] = '<!-- grav:=== -->';
            $lines[] = '';
            $lines[] = trim($this->convert($textParts['below'], $gravPath, $homeFile));
            $lines[] = '';
        }
        $lines = array_merge($lines, Helpers::generated($this->guideHomeFooter($guide, $gravPath, $homeFile, !$smallCover)));
        $this->writeFile($homeFile, implode("\n", $lines));
    }

    // A small cover with the guide's details beside it (the guide-cover class in Docsify-This and the Starter Kits):
    // the cover, then the title, subtitle, author(s), edition, licence and Start Reading
    private function guideCover(Page $guide, string $startReading): array
    {
        $details = ['# ' . $guide->title(), ''];
        foreach ([$guide->setting('subtitle'), $guide->setting('authors')] as $line) {
            if ($line !== '') {
                $details[] = $line;
                $details[] = '';
            }
        }
        $edition = $guide->setting('edition') . $this->updated($guide);
        if ($edition !== '') {
            $details[] = '<small>' . ltrim($edition, ' ·') . '</small>';
            $details[] = '';
        }
        // the licence as a pill, linked to the licence when there's an address
        $license = $guide->setting('license');
        if ($license !== '' && $guide->setting('license_url') !== '') {
            $details[] = '[' . $license . '](' . $guide->setting('license_url') . ' ":class=navpill")';
            $details[] = '';
        } elseif ($license !== '') {
            $details[] = '<span class="navpill">' . $license . '</span>';
            $details[] = '';
        }
        $details[] = $startReading;
        return array_merge(['<div class="guide-cover">', '', $this->coverImage($guide), '', '<div>', ''], $details, ['', '</div>', '', '</div>']);
    }

    // The cover image, from the guide's own folder (beside its home page). Without alt text, "<title> cover", as in Grav.
    private function coverImage(Page $guide): string
    {
        $alt = $guide->setting('cover_image_alt');
        if ($alt === '') {
            $alt = $guide->title() . ' cover';
        }
        return '![' . $alt . '](' . $guide->setting('cover_image') . ' ":no-zoom")';
    }

    // A section's card on the guide home: image, label and number, title, description
    private function sectionCard(Page $section, string $number, string $top, string $label): array
    {
        $card = ['<div class="card">', ''];
        $image = $section->setting('image');
        if ($image !== '') {
            // images are read from the page's own folder, links from the site's top
            $card[] = '[![' . $section->title() . '](' . $section->slug() . '/' . $image . ' ":no-zoom")](' . $top . $section->slug() . '/README.md)';
            $card[] = '';
        }
        $card[] = $label . ' ' . $number;
        $card[] = '';
        $card[] = '<h2><a href="#/' . $top . $section->slug() . '/README">' . $section->title() . '</a></h2>';
        $card[] = '';
        $description = $section->setting('description');
        if ($description !== '') {
            $card[] = $description;
            $card[] = '';
        }
        $card[] = '</div>';
        $card[] = '';
        return $card;
    }

    // The Side Box, the authors line (unless it's already in the cover) and the attribution, then the line that hides the sidebar
    private function guideHomeFooter(Page $guide, string $gravPath, string $homeFile, bool $authorsLine): array
    {
        $footer = [];
        $sideBox = $guide->setting('side_box');
        if ($sideBox !== '') {
            // the Side Box invites readers to act, so a TIP
            $footer[] = "> [!TIP]\n" . Helpers::quoteLines(trim($this->convert($sideBox, $gravPath, $homeFile)));
            $footer[] = '';
        }
        // (left out when the guide has no authors or edition, so there's no stray " · ")
        $hasAuthorsLine = $guide->setting('authors') !== '' || $guide->setting('edition') !== '';
        if ($authorsLine && $hasAuthorsLine) {
            $footer[] = $guide->setting('authors') . ' · ' . $guide->setting('edition') . $this->updated($guide);
            $footer[] = '';
        }
        $attribution = $guide->setting('attribution_text');
        if ($attribution !== '') {
            $footer[] = $attribution;
            $footer[] = '';
        }
        $footer[] = '<!-- Hides the sidebar on this page -->';
        $footer[] = self::HIDE_SIDEBAR;
        return $footer;
    }

    // The reading order: a group for each section with its pages (the guide home isn't listed: it hides the
    // sidebar, and every page leads back to it). Docsify can open and close a group only when it's at the top
    // level with a plain-text title, so the section's own page is the group's first link, as Overview.
    private function writeSidebar(Page $guide, string $top, array $sections): void
    {
        $lines = [];
        if (!$this->single) {
            // the guide's name first, in bold (not a link, so not a page); a single guide's site name is its name
            $lines = ['**' . $guide->title() . '**', ''];
        }
        foreach ($sections as $item) {
            $section = $item['page'];
            $sectionLink = $top . $section->slug() . '/README.md';
            if (count($section->children) === 0) {
                $lines[] = '- [' . $section->title() . '](' . $sectionLink . ')';
                continue;
            }
            $lines[] = '- ' . $section->title();
            $lines[] = '  - [Overview](' . $sectionLink . ')';
            foreach ($section->children as $page) {
                $lines[] = '  - [' . $page->title() . '](' . $top . $section->slug() . '/' . $page->slug() . '.md)';
            }
        }
        $this->writeFile($top . '_sidebar.md', implode("\n", $lines));
    }

    // A guide's card on the Guides page: cover image, title (with any badge), subtitle, authors and edition
    private function guideCard(Page $guide): array
    {
        $guideSlug = $guide->slug();
        $card = ['<div class="card">', ''];
        $cover = $guide->setting('cover_image');
        if ($cover !== '') {
            $alt = $guide->setting('cover_image_alt');
            if ($alt === '') {
                $alt = $guide->title();
            }
            $card[] = '[![' . $alt . '](' . $guideSlug . '/' . $cover . ' ":no-zoom")](' . $guideSlug . '/README.md)';
            $card[] = '';
        }
        // a badge such as "Draft" goes inside the title, so the cards still line up
        $badge = '';
        if ($guide->setting('badge_label') !== '') {
            $badge = ' <span class="badge">' . $guide->setting('badge_label') . '</span>';
        }
        $card[] = '<h2><a href="#/' . $guideSlug . '/README">' . $guide->title() . '</a>' . $badge . '</h2>';
        $card[] = '';
        $card[] = $guide->setting('subtitle');
        $card[] = '';
        $card[] = '<small>' . $guide->setting('authors') . '<br>' . $guide->setting('edition') . $this->updated($guide) . '</small>';
        $card[] = '';
        $card[] = '</div>';
        $card[] = '';
        return $card;
    }

    // The Guides page (Grav's Guides List page), with a card for each guide
    private function writeGuidesPage(?Page $page, array $cards): void
    {
        if ($page === null) {
            $page = new Page('guides', '00.guides', 'reader-list.md');
        }
        $gravPath = '/' . $page->slug();
        $textParts = $this->splitAtSeparator($page->body);
        $title = $page->setting('title');
        if ($title === '') {
            $title = 'Guides';
        }

        // the original site's address is kept with the home page, so links to it can go back to Grav's own form
        $noteExtra = [];
        if ($this->siteUrl !== '') {
            $noteExtra = ['site' => $this->siteUrl];
        }
        $lines = [Helpers::gravNote($page, $noteExtra), ''];
        $lines = array_merge($lines, Helpers::generated(['# ' . $title, '', $page->setting('subtitle')]), ['']);
        $lines[] = trim($this->convert($textParts['above'], $gravPath, 'home.md'));
        $lines[] = '';
        // three cards to a row (card-list-guides) when the Grav page asks for three; two (card-list) otherwise
        $cardList = 'card-list';
        if ($page->setting('cards_per_row') === '3') {
            $cardList = 'card-list card-list-guides';
        }
        // search lives in each guide's sidebar, which the Guides page and guide homes hide
        $searchAndCards = array_merge(
            ['Each guide has its own search box, on its reading pages.', '', '<div class="' . $cardList . '">', ''],
            $cards,
            ['</div>']
        );
        $lines = array_merge($lines, Helpers::generated($searchAndCards), ['']);
        if (trim($textParts['below']) !== '') {
            $lines[] = '<!-- grav:=== -->';
            $lines[] = '';
            $lines[] = trim($this->convert($textParts['below'], $gravPath, 'home.md'));
            $lines[] = '';
        }
        $lines = array_merge($lines, Helpers::generated(['<!-- Hides the sidebar on this page -->', self::HIDE_SIDEBAR]));
        $this->writeFile('home.md', implode("\n", $lines));
    }

    // $foot: anything the converter adds after the page's text (such as its Side Box)
    private function writePage(string $path, Page $page, array $head, string $body, array $foot = []): void
    {
        $lines = array_merge([Helpers::gravNote($page), ''], Helpers::generated($head), ['', trim($body)]);
        if (count($foot) > 0) {
            $lines[] = '';
            $lines = array_merge($lines, Helpers::generated($foot));
        }
        $this->writeFile($path, implode("\n", $lines));
    }

    // Grav Markdown → Docsify Markdown: callouts, links between guide pages, then links that only work in Grav.
    // $gravPath: the page's address on the Grav site; $where: the Docsify file, for warnings
    private function convert(string $markdown, string $gravPath, string $where): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        $markdown = Embeds::toDocsify($markdown);
        $markdown = Callouts::toDocsify($markdown);
        $markdown = Links::newTabToDocsify($markdown);
        $markdown = Links::toDocsify($markdown, $this->guideSlugs, $this->single);
        foreach (Callouts::unknownShortcodes($markdown) as $name) {
            $this->warnings[] = $where . ': the [' . $name . '] shortcode only works in Grav (left as it is)';
        }
        $markdown = Links::gravOnly($markdown, $gravPath, $this->siteUrl, $where, $this->warnings);
        return Helpers::showCode($markdown, $codeParts);
    }

    // A guide home's text is split by a line of === : the intro above the section cards, the rest below them
    private function splitAtSeparator(string $body): array
    {
        $parts = preg_split('/^===[ \t]*$/m', $body, 2);
        $below = '';
        if (isset($parts[1])) {
            $below = $parts[1];
        }
        return ['above' => $parts[0], 'below' => $below];
    }

    private function updated(Page $guide): string
    {
        $lastUpdated = $guide->setting('last_updated');
        if ($lastUpdated === '') {
            return '';
        }
        return ' · Updated ' . Helpers::shortDate($lastUpdated);
    }

    private function copyImages(Page $page, string $toFolder): void
    {
        foreach ($page->images as $image) {
            $target = $this->out . '/' . ltrim($toFolder . '/' . $image, '/');
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($this->pagesDir . '/' . $page->folder . '/' . $image, $target);
        }
    }

    private function writeFile(string $path, string $text): void
    {
        $target = $this->out . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, rtrim($text) . "\n");
    }
}
