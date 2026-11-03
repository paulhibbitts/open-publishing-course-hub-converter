<?php
namespace OPG;

// Writes an Open Course Hub course as a Docsify course, laid out like the Docsify Open Course Starter Kit: home.md, a
// page per module and menu page, images/, _sidebar.md (modules and LMS links), _navbar.md and _footer.md. Hidden notes
// keep what's needed to go back to the same Grav pages (see CourseDocsifyReader).
class CourseDocsifyWriter
{
    // Links that only work on the Grav site, and anything else worth checking (for conversion-notes.txt)
    public array $warnings = [];

    private string $pagesDir;
    private string $out;
    private string $siteUrl;
    // each converted page's Grav address => its Docsify file
    private array $addresses = [];
    // image name in images/ => the file it was copied from
    private array $copiedImages = [];
    // the links converted since the last hidden note was written, as [Docsify address, Grav address as written]
    private array $pageLinks = [];

    public function write(Site $site, string $pagesDir, string $out, string $siteUrl = ''): void
    {
        $this->pagesDir = $pagesDir;
        $this->out = $out;
        $this->siteUrl = $siteUrl;
        $this->warnings = [];
        $this->copiedImages = [];
        $modulesPage = $site->modulesPage;

        // every page's address first, so links between them are converted. The Modules page has no page of its own
        // (the sidebar lists the modules), so links to it go to the course home.
        $modulesPath = '/' . $modulesPage->slug();
        $this->addresses = ['/' => 'home.md', '/home' => 'home.md', $modulesPath => 'home.md'];
        foreach ($modulesPage->children as $module) {
            $this->addresses[$modulesPath . '/' . $module->slug()] = $module->slug() . '.md';
        }
        foreach ($site->coursePages as $page) {
            $this->addresses['/' . $page->slug()] = $page->slug() . '.md';
        }

        foreach ($modulesPage->children as $module) {
            $this->writeModule($module, $modulesPath);
        }
        $this->writeHome($site->courseHome, $modulesPage);

        $navbar = [];
        foreach ($site->coursePages as $page) {
            $this->writeMenuPage($page);
            $navbar[] = '- [' . $page->title() . '](' . $page->slug() . '.md)';
        }
        $sidebarGroups = [];
        if ($site->courseSidebar !== null) {
            $sidebarGroups = $this->writeContact($site->courseSidebar);
            if (is_file($this->out . '/contact.md')) {
                $navbar[] = '- [Contact](contact.md)';
            }
        }
        $this->writeFile('_navbar.md', implode("\n", $navbar));
        $this->writeSidebar($modulesPage, $sidebarGroups, $site->courseHome !== null);
        if ($site->courseFooter !== null) {
            $footer = trim($this->convert($this->withImagesFolder($site->courseFooter), '/', '_footer.md'));
            $this->writeFile('_footer.md', implode("\n", [$this->note($site->courseFooter), '', $footer]));
        }
    }

    // A module's page, as in the Starter Kits: its header image (with its credit), the module's description as its
    // title (or its title when it has no description), its Learning Objectives, then its text. A first heading that
    // repeats the description is kept in a hidden note instead of showing it twice.
    private function writeModule(Page $module, string $modulesPath): void
    {
        $file = $module->slug() . '.md';
        $gravPath = $modulesPath . '/' . $module->slug();
        $head = [];
        $headerImage = $this->headerImage($module);
        if ($headerImage !== '') {
            $head[] = '![' . $module->setting('header_image_alt_text') . '](' . $headerImage . ' \':class=banner-image\')';
            $head[] = '';
            if ($module->setting('header_image_credit') !== '') {
                $credit = $module->setting('header_image_credit');
                if ($module->setting('header_image_creditlink') !== '') {
                    $credit = '[' . $credit . '](' . $module->setting('header_image_creditlink') . ')';
                }
                $head[] = '<small>Photo: ' . $credit . '</small>';
                $head[] = '';
            }
        }
        $title = $module->setting('description');
        if ($title === '') {
            $title = $module->title();
        }
        $head[] = '# ' . $title;
        if ($module->setting('learning_objectives') !== '') {
            $head[] = '';
            $head[] = Callouts::quote('TIP', 'Learning Objectives', $module->setting('learning_objectives'));
        }
        $body = $this->withoutRepeatedHeading($this->withImagesFolder($module), $title);
        $body = $this->convert($body, $gravPath, $file);
        $lines = array_merge([$this->note($module), ''], Helpers::generated($head), ['', trim($body)]);
        $this->writeFile($file, implode("\n", $lines));
    }

    // '## **What is usability?**' at the start of the text, when the title is 'What is usability?', is kept as a
    // hidden note. When that was the text's only ## heading, its ### headings move up to ##, so the headings follow
    // on from the title (# then ##) as in the Starter Kits; the note says so, for the way back.
    private function withoutRepeatedHeading(string $body, string $title): string
    {
        $text = ltrim($body, "\n");
        $firstLine = strtok($text, "\n");
        if ($firstLine === false || preg_match('/^(#{1,6})\s+(.+?)\s*$/', $firstLine, $found) !== 1) {
            return $body;
        }
        if (trim($found[2], " *_") !== trim($title, " *_")) {
            return $body;
        }
        $rest = substr($text, strlen($firstLine));
        $codeParts = [];
        $restWithoutCode = Helpers::hideCode($rest, $codeParts);
        if ($found[1] === '##' && preg_match('/^##\s/m', $restWithoutCode) !== 1 && preg_match('/^###\s/m', $restWithoutCode) === 1) {
            $restWithoutCode = preg_replace('/^#(#{2,6}\s)/m', '$1', $restWithoutCode);
            return Embeds::hiddenLine($firstLine, true) . Helpers::showCode($restWithoutCode, $codeParts);
        }
        return Embeds::hiddenLine($firstLine) . $rest;
    }

    // The course home: the home page's own text, with each part it shows ([plugin:page-inject](/home/_part)) in its
    // place – this week's reminders (an alert box in Grav) in the Starter Kits' announcement box. Without a home page,
    // the Modules page's title and description.
    private function writeHome(?Page $home, Page $modulesPage): void
    {
        if ($home === null) {
            $head = ['# ' . $modulesPage->title()];
            if ($modulesPage->setting('description') !== '') {
                $head = array_merge($head, ['', $modulesPage->setting('description')]);
            }
            $extra = $this->siteNote();
            if (trim($modulesPage->body) !== '') {
                $extra['body'] = trim($modulesPage->body);
            }
            $lines = array_merge([$this->note($modulesPage, $extra), ''], Helpers::generated($head));
            $this->writeFile('home.md', implode("\n", $lines));
            return;
        }
        $parts = [];
        foreach ($home->children as $part) {
            $parts[$part->slug()] = $part;
        }
        // the home page's own text (anything besides its parts) is converted like any other page, a stretch at a time
        $ownText = [];
        $sections = [];
        // the links in the home page's own text, kept apart from each part's links
        $homeLinks = [];
        foreach (explode("\n", $home->body) as $line) {
            if (preg_match('/^\[plugin:page-inject\]\(\/[^)]*\/([^\/)]+)\)\s*$/', trim($line), $found) === 1 && isset($parts[$found[1]])) {
                $sections[] = $this->ownHomeText($ownText);
                $ownText = [];
                $homeLinks = array_merge($homeLinks, $this->pageLinks);
                $this->pageLinks = [];
                $sections[] = implode("\n", $this->homePart($parts[$found[1]], trim($line)));
                continue;
            }
            $ownText[] = $line;
        }
        $sections[] = $this->ownHomeText($ownText);
        $this->pageLinks = array_merge($homeLinks, $this->pageLinks);
        // the page's title as its heading (Grav hides it, with the site's name in its header instead), so the page has
        // an H1 like every other page. Its note comes last, so it holds the links of its own text.
        $head = array_merge([$this->note($home, $this->siteNote()), ''], Helpers::generated(['# ' . $home->title()]), ['']);
        $this->writeFile('home.md', implode("\n", $head) . implode("\n", $sections));
    }

    // A stretch of the home page's own text (often just the blank lines between its parts), converted
    private function ownHomeText(array $lines): string
    {
        return preg_replace("/\n{3,}/", "\n\n", $this->convert(implode("\n", $lines), '/home', 'home.md'));
    }

    // One part of the home page, with its Grav settings and the page-inject line it came from in hidden notes, and a
    // note where it ends (text after it is the home page's own)
    private function homePart(Page $part, string $injectLine): array
    {
        $body = trim($this->convert($this->withImagesFolder($part), '/home', 'home.md'));
        $lines = ['', $this->note($part), Embeds::hiddenLine($injectLine), ''];
        if ($part->file === 'alert.md') {
            // an alert box in Grav: the Starter Kits' announcement box (blank lines around the text, so Docsify
            // reads the Markdown inside it)
            $lines = array_merge($lines, Helpers::generated(['<blockquote class="announcement">', '']), ['', $body, ''], Helpers::generated(['', '</blockquote>']));
        } else {
            $lines = array_merge($lines, [$body]);
        }
        return array_merge($lines, ['', '<!-- grav:/part -->', '']);
    }

    // A menu page (Schedule, Topics …): its text, with its title added when the text doesn't start with one
    private function writeMenuPage(Page $page): void
    {
        $file = $page->slug() . '.md';
        $body = trim($this->convert($this->withImagesFolder($page), '/' . $page->slug(), $file));
        $lines = [$this->note($page), ''];
        if (!str_starts_with($body, '# ')) {
            $lines = array_merge($lines, Helpers::generated(['# ' . $page->title()]), ['']);
        }
        $lines[] = $body;
        $this->writeFile($file, implode("\n", $lines));
    }

    // The course sidebar, as in the Starter Kits: a section of links only (such as the LMS links) becomes a group in
    // _sidebar.md; the course details (instructor, office hours …) go on a Contact page. Where each group was, the
    // Contact page has a hidden note with the section's layout, so the way back puts it together again.
    // Returns the sidebar groups.
    private function writeContact(Page $sidebarPage): array
    {
        $text = trim($this->convert($this->withImagesFolder($sidebarPage), '/', 'contact.md'));
        $groups = [];
        $contact = [];
        foreach (preg_split('/^(?=#{1,6}\s)/m', $text) as $section) {
            $lines = explode("\n", trim($section));
            $heading = '';
            if (preg_match('/^#{1,6}\s+(.+?)\s*$/', $lines[0], $found) === 1) {
                $heading = $found[1];
                array_shift($lines);
            }
            $links = [];
            $onlyLinks = true;
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (preg_match('/^\s*[-*]?\s*(\[[^\]]+\]\([^)]+\))\s*$/', $line, $found) === 1) {
                    $links[] = $found[1];
                } else {
                    $onlyLinks = false;
                }
            }
            if ($heading !== '' && $onlyLinks && count($links) > 0) {
                $groups[] = '- **' . $heading . '**';
                foreach ($links as $link) {
                    $groups[] = '  - ' . $link;
                }
                $contact[] = self::groupNote($section, $heading, $links);
                continue;
            }
            if (trim($section) !== '') {
                $contact[] = trim($section);
            }
        }
        $hasDetails = false;
        foreach ($contact as $section) {
            if (!str_starts_with($section, '<!-- grav:sidebar-group ')) {
                $hasDetails = true;
            }
        }
        if ($hasDetails) {
            $lines = array_merge([$this->note($sidebarPage), ''], Helpers::generated(['# Contact']), ['', implode("\n\n", $contact)]);
            $this->writeFile('contact.md', implode("\n", $lines));
        }
        return $groups;
    }

    // A sidebar section of links, as a hidden note: the section with its heading and links taken out, e.g.
    // "## {heading}\n{link}  \n{link}", so the way back can fill in the heading and links from _sidebar.md
    private static function groupNote(string $section, string $heading, array $links): string
    {
        $layout = trim($section);
        $headingAt = strpos($layout, $heading);
        $layout = substr($layout, 0, $headingAt) . '{heading}' . substr($layout, $headingAt + strlen($heading));
        foreach ($links as $link) {
            $at = strpos($layout, $link);
            $layout = substr($layout, 0, $at) . '{link}' . substr($layout, $at + strlen($link));
        }
        $json = json_encode(['layout' => $layout], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<!-- grav:sidebar-group ' . str_replace('-->', '-->', $json) . ' -->';
    }

    // The modules in order, then any link groups from the course sidebar. With a home page, the Modules page's own
    // settings are kept here (it has no Docsify page of its own).
    private function writeSidebar(Page $modulesPage, array $groups, bool $hasHome): void
    {
        $lines = [];
        if ($hasHome) {
            $extra = [];
            if (trim($modulesPage->body) !== '') {
                $extra['body'] = trim($modulesPage->body);
            }
            $lines = [Helpers::gravNote($modulesPage, array_merge($extra, self::spacing($modulesPage))), ''];
        }
        foreach ($modulesPage->children as $module) {
            // hidden or unpublished modules aren't listed
            if (($module->meta['visible'] ?? true) === false || ($module->meta['published'] ?? true) === false) {
                continue;
            }
            $lines[] = '- [' . $module->title() . '](' . $module->slug() . '.md)';
        }
        $this->writeFile('_sidebar.md', implode("\n", array_merge($lines, $groups)));
    }

    // A page's hidden note, with the links converted since the last one (see Links::mapToDocsify) and the page's
    // spacing around its text when it isn't the usual one
    private function note(Page $page, array $extra = []): string
    {
        if (count($this->pageLinks) > 0) {
            $extra['links'] = $this->pageLinks;
        }
        $this->pageLinks = [];
        return Helpers::gravNote($page, array_merge($extra, self::spacing($page)));
    }

    // The blank space before and after a page's text, when it isn't a blank line before and a line end after
    private static function spacing(Page $page): array
    {
        $text = trim($page->body);
        $before = $page->body;
        $after = '';
        if ($text !== '') {
            $before = substr($page->body, 0, strpos($page->body, $text));
            $after = substr($page->body, strpos($page->body, $text) + strlen($text));
        }
        $spacing = [];
        if ($before !== "\n") {
            $spacing['before'] = $before;
        }
        if ($after !== "\n") {
            $spacing['after'] = $after;
        }
        return $spacing;
    }

    // The original site's address, kept with the home page (as for guides)
    private function siteNote(): array
    {
        if ($this->siteUrl === '') {
            return [];
        }
        return ['site' => $this->siteUrl];
    }

    // The header image Open Matter shows for a page (header_image: true): header_image_file, or the page's first
    // image. Returns its address in images/, or '' when there isn't one.
    private function headerImage(Page $page): string
    {
        if (empty($page->meta['header_image']) || count($page->images) === 0) {
            return '';
        }
        $file = $page->setting('header_image_file');
        $images = $page->images;
        sort($images);
        if ($file === '' || !in_array(basename($file), $images, true)) {
            $file = $images[0];
        }
        return $this->copyImage($page, basename($file));
    }

    // The page's text with its own images pointing to images/ (the images are copied there)
    private function withImagesFolder(Page $page): string
    {
        $body = $page->body;
        foreach ($page->images as $image) {
            if (!str_contains($body, $image)) {
                continue;
            }
            $address = $this->copyImage($page, $image);
            // ![alt](image.jpg …) and image.jpg?options – only links to the file itself, not to other folders
            $body = preg_replace('/(!\[[^\]]*\]\()' . preg_quote($image, '/') . '(?=[)?\s])/', '$1' . $address, $body);
        }
        return $body;
    }

    // Copies a page's image into images/ (named after the page as well when another page has an image of the same
    // name) and returns its address there
    private function copyImage(Page $page, string $image): string
    {
        $source = $this->pagesDir . '/' . $page->folder . '/' . $image;
        $name = $image;
        if (isset($this->copiedImages[$name]) && $this->copiedImages[$name] !== $source) {
            $name = $page->slug() . '-' . $image;
        }
        if (!isset($this->copiedImages[$name])) {
            $target = $this->out . '/images/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($source, $target);
            $this->copiedImages[$name] = $source;
        }
        return 'images/' . $name;
    }

    // Grav Markdown → Docsify Markdown, as for guides: embeds, callouts, links between the course's pages, then links
    // that only work on the Grav site
    private function convert(string $markdown, string $gravPath, string $where): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        $markdown = Embeds::toDocsify($markdown);
        $markdown = Callouts::toDocsify($markdown);
        $markdown = Links::newTabToDocsify($markdown);
        $markdown = Links::mapToDocsify($markdown, $this->addresses, $gravPath, $this->pageLinks);
        foreach (Callouts::unknownShortcodes($markdown) as $name) {
            $this->warnings[] = $where . ': the [' . $name . '] shortcode only works in Grav (left as it is)';
        }
        $markdown = Links::gravOnly($markdown, $gravPath, $this->siteUrl, $where, $this->warnings);
        return Helpers::showCode($markdown, $codeParts);
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
