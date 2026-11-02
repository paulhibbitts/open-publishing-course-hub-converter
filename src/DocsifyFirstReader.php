<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads a Docsify guide site that wasn't made by this converter (no hidden notes), laid out like the Docsify-This
// guide templates, and works out the Open Publishing Space structure: guide folders, numbered sections and pages,
// and their settings (titles, section numbers, Learning Objectives, cards, authors line …). Settings that only
// Grav has get the skeleton's defaults.
class DocsifyFirstReader
{
    private string $dir;
    // a single-guide site: the guide's home is home.md, and its sections are top-level folders
    private bool $single = false;
    private string $singleGuideSlug = '';
    // settings that couldn't be found and were given a default, for conversion-notes.txt
    public array $notes = [];
    // every Docsify file read, to find the pages that weren't converted
    private array $filesRead = [];
    // each Docsify page's address in Grav ('topic-three-subtopic-one.md' => '/guide/topic-three-overview/topic-three-subtopic-one'),
    // worked out from the sidebars first, so links reach a page wherever it ends up
    private array $gravAddresses = [];

    public function read(string $dir): Site
    {
        $this->dir = rtrim($dir, '/');
        $this->notes = [];
        $this->filesRead = [];
        $this->gravAddresses = [];
        $site = new Site();

        $guideFolders = $this->guideFolders();
        if (count($guideFolders) === 0 && is_file($this->dir . '/_sidebar.md')) {
            // no guide folders, but a sidebar at the top: a single-guide site
            $this->single = true;
            $home = $this->parseGuideHome($this->readFile('home.md'));
            $this->singleGuideSlug = Helpers::slugify($home['title']);
            $this->mapAddresses('', $this->singleGuideSlug);
            $site->guides[] = $this->readGuide('', $this->singleGuideSlug, 1, []);
            $this->noteUnconvertedPages();
            return $site;
        }

        // every guide's addresses first, so links between guides work too
        $this->gravAddresses['home.md'] = '/';
        foreach ($guideFolders as $folder) {
            $this->mapAddresses($folder . '/', $folder);
        }
        $guidesPage = $this->parseGuidesPage($this->readFile('home.md'));
        $site->guidesPage = $this->guidesPageToGrav($guidesPage);
        $number = 0;
        foreach ($this->inCardOrder($guideFolders, $guidesPage['cards']) as $folder) {
            $number++;
            $card = [];
            if (isset($guidesPage['cards'][$folder])) {
                $card = $guidesPage['cards'][$folder];
            }
            $site->guides[] = $this->readGuide($folder . '/', $folder, $number, $card);
        }
        $this->noteUnconvertedPages();
        return $site;
    }

    // Pages that aren't part of a guide (an About page, for example) aren't converted: they're listed in the notes.
    // The top README.md is a Docsify-This repo's GitHub page, not part of the site.
    private function noteUnconvertedPages(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
        $unconverted = [];
        foreach ($items as $item) {
            $path = substr($item->getPathname(), strlen($this->dir) + 1);
            $isMarkdown = str_ends_with($path, '.md');
            $isDocsifyFile = str_starts_with(basename($path), '_');
            if ($isMarkdown && !$isDocsifyFile && $path !== 'README.md' && !in_array($path, $this->filesRead, true)) {
                $unconverted[] = $path;
            }
        }
        sort($unconverted);
        foreach ($unconverted as $path) {
            $this->notes[] = $path . ' isn\'t part of a guide, so it wasn\'t converted';
        }
    }

    // ---- the guides ----

    // Each Docsify page's Grav address, from the guide's sidebar (sections and their pages) and navbar (menu pages)
    private function mapAddresses(string $base, string $guideSlug): void
    {
        $homeFile = $base . 'README.md';
        if ($this->single) {
            $homeFile = 'home.md';
        }
        $guideAddress = '/' . $guideSlug;
        $this->gravAddresses[$homeFile] = $guideAddress;

        $sidebarText = '';
        if (is_file($this->dir . '/' . $base . '_sidebar.md')) {
            $sidebarText = file_get_contents($this->dir . '/' . $base . '_sidebar.md');
        }
        foreach ($this->parseSidebar($sidebarText) as $entry) {
            $sectionAddress = $guideAddress . '/' . $this->sectionSlug($entry);
            $this->gravAddresses[$entry['file']] = $sectionAddress;
            foreach ($entry['pages'] as $pageEntry) {
                $this->gravAddresses[$pageEntry['file']] = $sectionAddress . '/' . $this->slugFromDocsifyFile($pageEntry['file']);
            }
        }

        // menu pages: the navbar's other links to the guide's own pages
        $navbarFile = $base . '_navbar.md';
        if (!is_file($this->dir . '/' . $navbarFile)) {
            return;
        }
        foreach ($this->links(file_get_contents($this->dir . '/' . $navbarFile)) as $link) {
            $file = $link['target'];
            $isGuidePage = $base === '' || str_starts_with($file, $base);
            if ($isGuidePage && !isset($this->gravAddresses[$file])) {
                $this->gravAddresses[$file] = $guideAddress . '/' . $this->slugFromDocsifyFile($file);
            }
        }
    }

    // Folders with their own _sidebar.md are guides
    private function guideFolders(): array
    {
        $folders = [];
        foreach (scandir($this->dir) as $name) {
            $isHidden = str_starts_with($name, '.');
            if (!$isHidden && is_dir($this->dir . '/' . $name) && is_file($this->dir . '/' . $name . '/_sidebar.md')) {
                $folders[] = $name;
            }
        }
        sort($folders);
        return $folders;
    }

    // The guides in the order of their cards on the Guides page (guides without a card come last)
    private function inCardOrder(array $folders, array $cards): array
    {
        $ordered = [];
        foreach (array_keys($cards) as $folder) {
            if (in_array($folder, $folders, true)) {
                $ordered[] = $folder;
            }
        }
        foreach ($folders as $folder) {
            if (!in_array($folder, $ordered, true)) {
                $ordered[] = $folder;
            }
        }
        return $ordered;
    }

    // $base: where the guide's files are ('guide/', or '' for a single guide); $card: its card on the Guides page
    private function readGuide(string $base, string $slug, int $number, array $card): Page
    {
        $homeFile = $base . 'README.md';
        if ($this->single) {
            $homeFile = 'home.md';
        }
        $home = $this->parseGuideHome($this->readFile($homeFile));
        $guideFolder = sprintf('%02d', $number) . '.' . $slug;
        $guide = new Page('guide', $guideFolder, 'section-list.md');
        $guide->body = $this->toGravText($home['text']);
        // images shown on the guide home itself go in the guide's folder
        $this->addImages($guide, $home['text'], $homeFile);

        $sidebar = $this->parseSidebar($this->readFile($base . '_sidebar.md'));
        $label = '';
        $sectionNumber = 0;
        foreach ($sidebar as $entry) {
            $sectionNumber++;
            $sectionCard = [];
            if (isset($home['cards'][$entry['file']])) {
                $sectionCard = $home['cards'][$entry['file']];
            }
            $section = $this->readSection($guideFolder, $sectionNumber, $entry, $sectionCard, $homeFile);
            if ($label === '' && isset($section->meta['_label'])) {
                $label = $section->meta['_label'];
            }
            unset($section->meta['_label']);
            $section->yaml = $this->yaml($section->meta);
            $guide->children[] = $section;
        }
        if ($label === '') {
            $label = 'Section';
        }

        $guide->meta = $this->guideSettings($home, $card, $label);
        $this->addCoverImage($guide, $card);
        $guide->yaml = $this->yaml($guide->meta);
        $guide->menuPages = $this->readMenuPages($base, $guideFolder, $sidebar);
        return $guide;
    }

    // The guide home's settings, in the skeleton's order: from the page itself, then its card on the Guides page,
    // then the skeleton's defaults
    private function guideSettings(array $home, array $card, string $label): array
    {
        $settings = ['title' => $home['title']];
        $subtitle = $home['subtitle'];
        if ($subtitle === '' && isset($card['subtitle'])) {
            $subtitle = $card['subtitle'];
        }
        if ($subtitle !== '') {
            $settings['subtitle'] = $subtitle;
        }
        if (isset($card['cover_image'])) {
            $settings['cover_image'] = $card['cover_image'];
            $settings['cover_image_layout'] = 'small';
            // the card uses the guide's title when there's no alt text, so only a different one is kept
            if ($card['cover_image_alt'] !== '' && $card['cover_image_alt'] !== $home['title']) {
                $settings['cover_image_alt'] = $card['cover_image_alt'];
            }
        }
        if (isset($card['badge_label'])) {
            $settings['badge_label'] = $card['badge_label'];
        }
        // the authors line: from the guide home, or from its card
        foreach (['authors', 'edition', 'last_updated'] as $name) {
            if ($home[$name] !== '') {
                $settings[$name] = $home[$name];
            } elseif (isset($card[$name]) && $card[$name] !== '') {
                $settings[$name] = $card[$name];
            }
        }
        $settings['section_label'] = $label;
        $settings['show_reading_progress'] = true;
        $settings['prev_next_position'] = 'both';
        $settings['cards_per_row'] = '3';
        $license = $this->licenseIn($home['attribution_text']);
        if ($license !== null) {
            $settings['show_oer_attribution'] = true;
            $settings['license'] = $license['name'];
            $settings['license_url'] = $license['url'];
        }
        if ($home['attribution_text'] !== '') {
            $settings['attribution_text'] = $home['attribution_text'];
        }
        $settings['published'] = true;
        $settings['hide_page_title'] = false;
        $settings['child_type'] = 'section';
        $settings['hide_git_sync_repo_link'] = false;
        $settings['show_search_box'] = true;
        $settings['keep_my_place'] = true;
        if ($home['side_box'] !== '') {
            // Grav's editor ends this multi-line setting with a new line
            $settings['side_box'] = $home['side_box'] . "\n";
        }
        $settings['hide_next_prev_page_buttons'] = false;
        return $settings;
    }

    // The Creative Commons licence linked in the attribution line ('… licensed under <a href="…">CC BY 4.0</a>'),
    // as ['name', 'url'], or null when there isn't one
    private function licenseIn(string $attribution): ?array
    {
        $pattern = '/<a href="(https:\/\/creativecommons\.org\/[^"]+)">([^<]+)<\/a>/';
        if (preg_match($pattern, $attribution, $found) !== 1) {
            return null;
        }
        return ['name' => $found[2], 'url' => $found[1]];
    }

    private function addCoverImage(Page $guide, array $card): void
    {
        if (!isset($card['cover_source'])) {
            return;
        }
        $source = Helpers::imageInside($this->dir, $card['cover_source']);
        if ($source !== '') {
            $guide->images[] = $card['cover_image'];
            $guide->imageSources[$card['cover_image']] = $source;
        }
    }

    // ---- sections and pages ----

    // $entry: the section in the sidebar ['title', 'file', 'pages']; $card: its card on the guide home
    private function readSection(string $guideFolder, int $number, array $entry, array $card, string $homeFile): Page
    {
        $sectionSlug = $this->sectionSlug($entry);
        $sectionFolder = $guideFolder . '/' . sprintf('%02d', $number) . '.' . $sectionSlug;
        $section = new Page('section', $sectionFolder, 'section.md');
        $parsed = $this->parsePage($this->readFile($entry['file']), $entry['title']);
        if ($entry['group']) {
            // a sidebar group's name is the section's title (its first page may be called Overview)
            $parsed['title'] = $entry['title'];
        }
        $section->body = $this->toGravText($parsed['body']);
        $this->addImages($section, $parsed['body'], $entry['file']);

        $settings = ['title' => $parsed['title']];
        $sectionNumber = (string)$number;
        if ($parsed['number'] !== '') {
            $sectionNumber = $parsed['number'];
        } elseif (isset($card['number'])) {
            $sectionNumber = $card['number'];
        }
        $settings['section_number'] = $sectionNumber;
        if (isset($card['description'])) {
            $settings['description'] = $card['description'];
        }
        if ($parsed['objectives'] !== '') {
            $settings['learning_objectives'] = $parsed['objectives'];
        }
        if (isset($card['image'])) {
            // the card's image, from the guide home's folder
            $imageName = basename($card['image']);
            $settings['image'] = $imageName;
            $source = Helpers::imageInside($this->dir, $this->dir . '/' . $this->folderOf($homeFile) . $card['image']);
            if ($source !== '') {
                $section->images[] = $imageName;
                $section->imageSources[$imageName] = $source;
            }
        }
        $settings['child_type'] = 'section-page';
        // the section label ('Section', 'Part' …) is passed up to the guide, then removed
        $label = $parsed['label'];
        if ($label === '' && isset($card['label'])) {
            $label = $card['label'];
        }
        if ($label !== '') {
            $settings['_label'] = $label;
        }
        $section->meta = $settings;

        $pageNumber = 0;
        foreach ($entry['pages'] as $pageEntry) {
            $pageNumber++;
            $pageFolder = $sectionFolder . '/' . sprintf('%02d', $pageNumber) . '.' . $this->slugFromDocsifyFile($pageEntry['file']);
            $page = new Page('page', $pageFolder, 'section-page.md');
            $parsedPage = $this->parsePage($this->readFile($pageEntry['file']), $pageEntry['title']);
            $page->body = $this->toGravText($parsedPage['body']);
            $this->addImages($page, $parsedPage['body'], $pageEntry['file']);
            $page->meta = ['title' => $parsedPage['title']];
            $page->yaml = $this->yaml($page->meta);
            $section->children[] = $page;
        }
        return $section;
    }

    // Pages linked from the guide's navbar that aren't in its reading order (Topics, Resources …) become Grav
    // menu pages: unnumbered folders, in the navbar's order
    private function readMenuPages(string $base, string $guideFolder, array $sidebar): array
    {
        $navbarFile = $base . '_navbar.md';
        if ($this->single) {
            $navbarFile = '_navbar.md';
        }
        if (!is_file($this->dir . '/' . $navbarFile)) {
            return [];
        }
        $inReadingOrder = [];
        foreach ($sidebar as $entry) {
            $inReadingOrder[] = $entry['file'];
            foreach ($entry['pages'] as $pageEntry) {
                $inReadingOrder[] = $pageEntry['file'];
            }
        }

        $menuPages = [];
        $menuOrder = 0;
        foreach ($this->links($this->readFile($navbarFile)) as $link) {
            $file = $link['target'];
            // only the guide's own pages (not All Guides, the home page, or pages elsewhere on the site)
            $isGuidePage = $base === '' || str_starts_with($file, $base);
            $isHome = basename($file) === 'README.md' || basename($file) === 'home.md';
            if (!$isGuidePage || $isHome || in_array($file, $inReadingOrder, true) || !is_file($this->dir . '/' . $file)) {
                continue;
            }
            $menuOrder++;
            $page = new Page('menu', $guideFolder . '/' . $this->slugFromDocsifyFile($file), 'default.md');
            // a menu page's text keeps its own title, as on the Grav site
            $parsed = $this->parsePage($this->readFile($file), $link['text']);
            $page->body = $this->toGravText('# ' . $parsed['title'] . "\n\n" . $parsed['body']);
            $this->addImages($page, $parsed['body'], $file);
            $page->meta = ['title' => $parsed['title'], 'menu_order' => $menuOrder];
            $page->yaml = $this->yaml($page->meta);
            $menuPages[] = $page;
        }
        return $menuPages;
    }

    // ---- reading Docsify pages ----

    // A reading page: [← back link], # Title, <small>Section 1</small>, a Learning Objectives callout, then the text.
    // Returns ['title', 'label', 'number', 'objectives', 'body'].
    private function parsePage(string $text, string $titleFromSidebar): array
    {
        $lines = explode("\n", $this->withoutHideSidebar($text));
        $result = ['title' => $titleFromSidebar, 'label' => '', 'number' => '', 'objectives' => '', 'body' => ''];
        $i = $this->skipBlankLines($lines, 0);

        // the back link to the guide home
        if (isset($lines[$i]) && preg_match('/^\[← .*\]\(.*\)$/', $lines[$i]) === 1) {
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        if (isset($lines[$i]) && str_starts_with($lines[$i], '# ')) {
            $result['title'] = trim(substr($lines[$i], 2));
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // the section label and number: <small>Section 1</small> or <small>Part 1 of 6</small>
        if (isset($lines[$i]) && preg_match('/^<small>(.+?) (\d+)(?: of \d+)?<\/small>$/', $lines[$i], $found) === 1) {
            $result['label'] = $found[1];
            $result['number'] = $found[2];
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // a Learning Objectives callout right after the title
        if (isset($lines[$i]) && isset($lines[$i + 1]) && str_starts_with($lines[$i], '> [!') && trim($lines[$i + 1]) === '> **Learning Objectives**') {
            $objectives = [];
            $j = $i + 2;
            while ($j < count($lines) && preg_match('/^>( |$)/', $lines[$j]) === 1) {
                $objectives[] = preg_replace('/^> ?/', '', $lines[$j]);
                $j++;
            }
            $result['objectives'] = trim(implode("\n", $objectives));
            $i = $this->skipBlankLines($lines, $j);
        }
        $result['body'] = trim(implode("\n", array_slice($lines, $i)));
        return $result;
    }

    // A guide home: # Title, subtitle, intro, Start Reading, the section cards, more text, the Side Box (the last
    // callout), the authors line and the attribution
    private function parseGuideHome(string $text): array
    {
        $lines = explode("\n", $this->withoutHideSidebar($text));
        $home = ['title' => '', 'subtitle' => '', 'authors' => '', 'edition' => '', 'last_updated' => '',
            'attribution_text' => '', 'side_box' => '', 'cards' => [], 'text' => ''];

        $i = $this->readTitleAndSubtitle($lines, $home);
        $cardsStart = $this->findLine($lines, '/^<div class="card-list/', $i);
        $startReading = $this->findLine($lines, '/^\[Start Reading\]/', $i);
        $introEnd = $cardsStart;
        if ($startReading !== -1 && ($cardsStart === -1 || $startReading < $cardsStart)) {
            $introEnd = $startReading;
        }
        if ($introEnd === -1) {
            // no cards: everything after the subtitle is the guide's text
            $home['text'] = trim(implode("\n", array_slice($lines, $i)));
            return $home;
        }
        $intro = trim(implode("\n", array_slice($lines, $i, $introEnd - $i)));

        $after = [];
        if ($cardsStart !== -1) {
            $cardsEnd = $this->closingDiv($lines, $cardsStart);
            $cardLines = array_slice($lines, $cardsStart + 1, $cardsEnd - $cardsStart - 1);
            $home['cards'] = $this->parseSectionCards($cardLines);
            $after = array_slice($lines, $cardsEnd + 1);
        }
        $below = $this->readFooter($after, $home);

        // in Grav, the text below the cards follows a line of ===
        $home['text'] = $intro;
        if ($below !== '') {
            $home['text'] = $intro . "\n\n===\n\n" . $below;
        }
        return $home;
    }

    // Reads '# Title' and the one-line subtitle after it into $page; returns where the next part starts
    private function readTitleAndSubtitle(array $lines, array &$page): int
    {
        $i = $this->skipBlankLines($lines, 0);
        if (isset($lines[$i]) && str_starts_with($lines[$i], '# ')) {
            $page['title'] = trim(substr($lines[$i], 2));
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // a subtitle is a short one-line paragraph that isn't a link, an image, HTML or a callout
        $isOneLine = isset($lines[$i]) && (!isset($lines[$i + 1]) || trim($lines[$i + 1]) === '');
        if ($isOneLine && strlen($lines[$i]) <= 120 && preg_match('/^[\[!<>#-]/', $lines[$i]) !== 1) {
            $page['subtitle'] = trim($lines[$i]);
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        return $i;
    }

    // From the end of a guide home: the attribution, the authors line (Authors · Edition · Updated Oct 4, 2026)
    // and the Side Box (the callout just before them). Returns the text that's left, above them.
    private function readFooter(array $lines, array &$home): string
    {
        $end = count($lines);
        $last = $this->previousTextLine($lines, $end);
        // the attribution: a line with a licence, after the authors line
        if ($last !== -1 && stripos($lines[$last], 'licensed') !== false) {
            $home['attribution_text'] = trim($lines[$last]);
            $end = $last;
            $last = $this->previousTextLine($lines, $end);
        }
        if ($last !== -1 && preg_match('/^([^·]+?) · ([^·]+?)(?: · Updated (.+))?$/u', trim($lines[$last]), $found) === 1) {
            $home['authors'] = $found[1];
            $home['edition'] = $found[2];
            if (isset($found[3])) {
                $home['last_updated'] = $this->gravDate($found[3]);
            }
            $end = $last;
            $last = $this->previousTextLine($lines, $end);
        }
        // the Side Box: a callout ending just above the authors line
        if ($last !== -1 && str_starts_with($lines[$last], '>')) {
            $start = $last;
            while ($start > 0 && str_starts_with($lines[$start - 1], '>')) {
                $start--;
            }
            $quoted = [];
            for ($j = $start + 1; $j <= $last; $j++) {
                $quoted[] = preg_replace('/^> ?/', '', $lines[$j]);
            }
            $home['side_box'] = trim(implode("\n", $quoted));
            $end = $start;
        }
        return trim(implode("\n", array_slice($lines, 0, $end)));
    }

    // The section cards: <div class="card"> blocks, each with an image, a label and number, a title and a description.
    // Returns cards by the Docsify file they link to.
    private function parseSectionCards(array $lines): array
    {
        $cards = [];
        foreach ($this->cardBlocks($lines) as $cardLines) {
            $card = [];
            $textLines = [];
            foreach ($cardLines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^\[!\[[^\]]*\]\(([^)\s"]+)[^)]*\)\]\(([^)\s]+)\)$/', $line, $found) === 1) {
                    $card['image'] = $found[1];
                } elseif (preg_match('/<h2><a href="#\/([^"]+)">(.*?)<\/a>/', $line, $found) === 1) {
                    $card['file'] = $this->docsifyFile($found[1]);
                    $card['title'] = strip_tags($found[2]);
                } elseif (preg_match('/^(?:<small>)?(.+?) (\d+)(?: of \d+)?(?:<\/small>)?$/', $line, $found) === 1 && !isset($card['file'])) {
                    // the label line comes before the title
                    $card['label'] = $found[1];
                    $card['number'] = $found[2];
                } elseif (isset($card['file'])) {
                    $textLines[] = $line;
                }
            }
            if (count($textLines) > 0) {
                $card['description'] = implode("\n", $textLines);
            }
            if (isset($card['file'])) {
                $cards[$card['file']] = $card;
            }
        }
        return $cards;
    }

    // ---- the Guides page ----

    // The Guides page: # Title, subtitle, intro, a card for each guide, more text
    private function parseGuidesPage(string $text): array
    {
        $lines = explode("\n", $this->withoutHideSidebar($text));
        $page = ['title' => 'Guides', 'subtitle' => '', 'cards' => [], 'text' => '', 'cards_per_row' => 2];
        $i = $this->readTitleAndSubtitle($lines, $page);
        $cardsStart = $this->findLine($lines, '/^<div class="card-list/', $i);
        if ($cardsStart === -1) {
            $page['text'] = trim(implode("\n", array_slice($lines, $i)));
            return $page;
        }
        // three guide cards to a row with card-list-guides, two with card-list
        if (str_contains($lines[$cardsStart], 'card-list-guides')) {
            $page['cards_per_row'] = 3;
        }
        $introLines = [];
        for ($j = $i; $j < $cardsStart; $j++) {
            // the converter's own note about search isn't part of the page's text
            if (trim($lines[$j]) !== 'Each guide has its own search box, on its reading pages.') {
                $introLines[] = $lines[$j];
            }
        }
        $cardsEnd = $this->closingDiv($lines, $cardsStart);
        $page['cards'] = $this->parseGuideCards(array_slice($lines, $cardsStart + 1, $cardsEnd - $cardsStart - 1));
        $below = trim(implode("\n", array_slice($lines, $cardsEnd + 1)));
        $page['text'] = trim(implode("\n", $introLines));
        if ($below !== '') {
            $page['text'] .= "\n\n===\n\n" . $below;
        }
        return $page;
    }

    // A guide's card: cover image, title (with any badge), subtitle, <small>authors<br>edition · Updated date</small>.
    // Returns cards by guide folder.
    private function parseGuideCards(array $lines): array
    {
        $cards = [];
        foreach ($this->cardBlocks($lines) as $cardLines) {
            $card = [];
            foreach ($cardLines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^\[!\[([^\]]*)\]\(([^)\s"]+)[^)]*\)\]\(([^)\s]+)\)$/', $line, $found) === 1) {
                    $card['cover_image_alt'] = $found[1];
                    $card['cover_image'] = basename($found[2]);
                    $card['cover_source'] = $this->dir . '/' . $found[2];
                } elseif (preg_match('/<h2><a href="#\/([^\/"]+)\/README">(.*?)<\/a>(.*)<\/h2>/', $line, $found) === 1) {
                    $card['folder'] = $found[1];
                    if (preg_match('/<span class="badge">(.*?)<\/span>/', $found[3], $badge) === 1) {
                        $card['badge_label'] = $badge[1];
                    }
                } elseif (preg_match('/^<small>(.*?)<br>(.*?)(?: · Updated (.+))?<\/small>$/u', $line, $found) === 1) {
                    $card['authors'] = $found[1];
                    $card['edition'] = $found[2];
                    if (isset($found[3])) {
                        $card['last_updated'] = $this->gravDate($found[3]);
                    }
                } elseif (isset($card['folder']) && !isset($card['subtitle'])) {
                    $card['subtitle'] = $line;
                }
            }
            if (isset($card['folder'])) {
                $cards[$card['folder']] = $card;
            }
        }
        return $cards;
    }

    private function guidesPageToGrav(array $guidesPage): Page
    {
        $page = new Page('guides', '00.guides', 'reader-list.md');
        $page->meta = ['title' => $guidesPage['title']];
        if ($guidesPage['subtitle'] !== '') {
            $page->meta['subtitle'] = $guidesPage['subtitle'];
        }
        $page->meta['cards_per_row'] = $guidesPage['cards_per_row'];
        $page->meta['published'] = true;
        $page->yaml = $this->yaml($page->meta);
        $page->body = $this->toGravText($guidesPage['text']);
        return $page;
    }

    // ---- small helpers ----

    // The sidebar's reading order: '- [Title](file.md)' is a section on its own; '- Title' starts a group whose
    // first link (Overview) is the section's page and whose other links are its pages. A link may also have a
    // tooltip title: [Overview](file.md "Overview Chapter 3").
    private function parseSidebar(string $text): array
    {
        $sections = [];
        $group = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^- \[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $line, $found) === 1) {
                $sections[] = ['title' => $found[1], 'file' => $found[2], 'pages' => [], 'group' => false];
                $group = null;
            } elseif (preg_match('/^- (.+)$/', $line, $found) === 1) {
                // a group: its name is the section's title
                $group = count($sections);
                $sections[] = ['title' => trim($found[1]), 'file' => '', 'pages' => [], 'group' => true];
            } elseif ($group !== null && preg_match('/^\s+- \[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $line, $found) === 1) {
                if ($sections[$group]['file'] === '') {
                    $sections[$group]['file'] = $found[2];
                } else {
                    $sections[$group]['pages'][] = ['title' => $found[1], 'file' => $found[2]];
                }
            }
        }
        // a group without any links isn't a section
        $withFiles = [];
        foreach ($sections as $section) {
            if ($section['file'] !== '') {
                $withFiles[] = $section;
            }
        }
        return $withFiles;
    }

    // Splits lines into <div class="card"> … </div> blocks
    private function cardBlocks(array $lines): array
    {
        $blocks = [];
        $i = 0;
        while ($i < count($lines)) {
            if (str_starts_with(trim($lines[$i]), '<div class="card')) {
                $end = $this->closingDiv($lines, $i);
                $blocks[] = array_slice($lines, $i + 1, $end - $i - 1);
                $i = $end + 1;
            } else {
                $i++;
            }
        }
        return $blocks;
    }

    // The line of the </div> that closes the <div> on line $start (cards are <div>s inside a <div>)
    private function closingDiv(array $lines, int $start): int
    {
        $depth = 0;
        for ($i = $start; $i < count($lines); $i++) {
            $depth += substr_count($lines[$i], '<div');
            $depth -= substr_count($lines[$i], '</div>');
            if ($depth === 0) {
                return $i;
            }
        }
        return count($lines) - 1;
    }

    // Images a page shows (![alt](file)), copied beside its Grav page, with the link changed to just the file name
    private function addImages(Page $page, string $markdown, string $docsifyFile): void
    {
        preg_match_all('/!\[[^\]]*\]\(([^)\s"]+)/', $markdown, $found);
        foreach ($found[1] as $source) {
            if (str_contains($source, '://')) {
                continue;
            }
            // only an image file inside the site is copied
            $path = Helpers::imageInside($this->dir, $this->dir . '/' . $this->folderOf($docsifyFile) . $source);
            if ($path === '') {
                $this->notes[] = $docsifyFile . ': image "' . $source . '" wasn\'t found in the site';
                continue;
            }
            $name = basename($source);
            if (!in_array($name, $page->images, true)) {
                $page->images[] = $name;
                $page->imageSources[$name] = $path;
            }
            if ($source !== $name) {
                $page->body = str_replace('](' . $source, '](' . $name, $page->body);
            }
        }
    }

    // Docsify Markdown → Grav Markdown: callouts back to shortcodes where they match, links in Grav's form
    private function toGravText(string $markdown): string
    {
        $markdown = Callouts::toGrav($markdown);
        // links to pages whose Grav address is known (from the sidebars); any others are worked out from their path
        $markdown = preg_replace_callback('/\]\(([A-Za-z0-9._\/-]+\.md)(\?id=[^)\s]*)?\)/', [$this, 'linkToGravAddress'], $markdown);
        $singleGuide = '';
        if ($this->single) {
            $singleGuide = $this->singleGuideSlug;
        }
        return Links::toGrav($markdown, $singleGuide, '');
    }

    // $match: [whole link end, the Docsify file, ?id=heading]
    private function linkToGravAddress(array $match): string
    {
        if (!isset($this->gravAddresses[$match[1]])) {
            return $match[0];
        }
        $anchor = '';
        if (isset($match[2]) && $match[2] !== '') {
            $anchor = '#' . substr($match[2], strlen('?id='));
        }
        return '](' . $this->gravAddresses[$match[1]] . $anchor . ')';
    }

    // Links in a navbar or text: [['text' => …, 'target' => …], …]
    private function links(string $markdown): array
    {
        preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $markdown, $found, PREG_SET_ORDER);
        $links = [];
        foreach ($found as $match) {
            $links[] = ['text' => $match[1], 'target' => $match[2]];
        }
        return $links;
    }

    // The line that hides the sidebar (and the comment above it) isn't part of the author's text
    private function withoutHideSidebar(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace('<!-- Hides the sidebar on this page -->', '', $text);
        return preg_replace('/^<style>aside\.sidebar.*<\/style>\s*$/m', '', $text);
    }

    // 'guide/section/README' (from a card link) → 'guide/section/README.md'; 'guide/why-it-matters' → 'guide/why-it-matters.md'
    private function docsifyFile(string $route): string
    {
        return $route . '.md';
    }

    // A section's folder name: its own folder's name ('guide/section/README.md' → 'section'), or for a group of
    // flat files, the group's name ('Topic Three' → 'topic-three'), or the file's name
    private function sectionSlug(array $entry): string
    {
        if (basename($entry['file']) === 'README.md' || !$entry['group']) {
            return $this->slugFromDocsifyFile($entry['file']);
        }
        return Helpers::slugify($entry['title']);
    }

    // 'guide/section/README.md' → 'section'; 'guide/section/page.md' → 'page'
    private function slugFromDocsifyFile(string $file): string
    {
        if (basename($file) === 'README.md') {
            return basename(dirname($file));
        }
        return basename($file, '.md');
    }

    // 'guide/section/page.md' → 'guide/section/' ('' for a file at the top)
    private function folderOf(string $file): string
    {
        $folder = dirname($file);
        if ($folder === '.') {
            return '';
        }
        return $folder . '/';
    }

    // 'Oct 4, 2026' → '04-10-2026 00:00' (Grav's day-first date)
    private function gravDate(string $shown): string
    {
        $date = \DateTime::createFromFormat('M j, Y', trim($shown));
        if ($date === false) {
            return trim($shown);
        }
        return $date->format('d-m-Y') . ' 00:00';
    }

    private function skipBlankLines(array $lines, int $i): int
    {
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }
        return $i;
    }

    // The previous non-blank line before $before, or -1
    private function previousTextLine(array $lines, int $before): int
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) !== '') {
                return $i;
            }
        }
        return -1;
    }

    // The first line from $from that matches $pattern, or -1
    private function findLine(array $lines, string $pattern, int $from): int
    {
        for ($i = $from; $i < count($lines); $i++) {
            if (preg_match($pattern, $lines[$i]) === 1) {
                return $i;
            }
        }
        return -1;
    }

    private function readFile(string $path): string
    {
        $this->filesRead[] = $path;
        $full = $this->dir . '/' . $path;
        if (!is_file($full)) {
            $this->notes[] = $path . ' wasn\'t found';
            return '';
        }
        return file_get_contents($full);
    }

    private function yaml(array $settings): string
    {
        return rtrim(Yaml::dump($settings, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }
}
