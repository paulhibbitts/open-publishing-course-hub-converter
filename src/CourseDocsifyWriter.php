<?php
namespace OPG;

// Writes the modules of an Open Course Hub course as a Docsify course, laid out like the Docsify Open Course Starter
// Kit: every page at the site's top – home.md (the course home), a page per module and the menu pages – with images in
// images/, the modules (and the sidebar's link lists, such as the LMS links) in _sidebar.md, the menu pages and Contact
// in _navbar.md, and the footer in _footer.md. The same Markdown pages work in a repo shown by Docsify-This and in a
// Starter Kit's docs/ folder.
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
        $this->writeSidebar($modulesPage, $sidebarGroups);
        if ($site->courseFooter !== null) {
            $footer = trim($this->convert($this->withImagesFolder($site->courseFooter), '/', '_footer.md'));
            $this->writeFile('_footer.md', implode("\n", [Helpers::gravNote($site->courseFooter), '', $footer]));
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
        $lines = array_merge([Helpers::gravNote($module), ''], Helpers::generated($head), ['', trim($body)]);
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
            $lines = array_merge([Helpers::gravNote($modulesPage, $this->siteNote()), ''], Helpers::generated($head));
            $this->writeFile('home.md', implode("\n", $lines));
            return;
        }
        $parts = [];
        foreach ($home->children as $part) {
            $parts[$part->slug()] = $part;
        }
        // the page's title as its heading (Grav hides it, with the site's name in its header instead), so the page has
        // an H1 like every other page
        $lines = array_merge([Helpers::gravNote($home, $this->siteNote()), ''], Helpers::generated(['# ' . $home->title()]), ['']);
        foreach (explode("\n", $home->body) as $line) {
            if (preg_match('/^\[plugin:page-inject\]\(\/[^)]*\/([^\/)]+)\)\s*$/', trim($line), $found) === 1 && isset($parts[$found[1]])) {
                $lines = array_merge($lines, $this->homePart($parts[$found[1]], trim($line)));
                continue;
            }
            $lines[] = $line;
        }
        $text = implode("\n", $lines);
        // the home page's own text (anything besides its parts) is converted like any other page
        $this->writeFile('home.md', preg_replace("/\n{3,}/", "\n\n", $text));
    }

    // One part of the home page, with its Grav settings and the page-inject line it came from in hidden notes
    private function homePart(Page $part, string $injectLine): array
    {
        $body = trim($this->convert($this->withImagesFolder($part), '/home', 'home.md'));
        $lines = [Helpers::gravNote($part), Embeds::hiddenLine($injectLine), ''];
        if ($part->file === 'alert.md') {
            // an alert box in Grav: the Starter Kits' announcement box (blank lines around the text, so Docsify
            // reads the Markdown inside it)
            return array_merge($lines, Helpers::generated(['<blockquote class="announcement">', '']), ['', $body, ''], Helpers::generated(['', '</blockquote>']), ['']);
        }
        return array_merge($lines, [$body, '']);
    }

    // A menu page (Schedule, Topics …): its text, with its title added when the text doesn't start with one
    private function writeMenuPage(Page $page): void
    {
        $file = $page->slug() . '.md';
        $body = trim($this->convert($this->withImagesFolder($page), '/' . $page->slug(), $file));
        $lines = [Helpers::gravNote($page), ''];
        if (!str_starts_with($body, '# ')) {
            $lines = array_merge($lines, Helpers::generated(['# ' . $page->title()]), ['']);
        }
        $lines[] = $body;
        $this->writeFile($file, implode("\n", $lines));
    }

    // The course sidebar, as in the Starter Kits: a section of links only (such as the LMS links) becomes a group in
    // _sidebar.md; the course details (instructor, office hours …) go on a Contact page. Returns the sidebar groups.
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
                continue;
            }
            if (trim($section) !== '') {
                $contact[] = trim($section);
            }
        }
        if (count($contact) > 0) {
            $lines = array_merge([Helpers::gravNote($sidebarPage), ''], Helpers::generated(['# Contact']), ['', implode("\n\n", $contact)]);
            $this->writeFile('contact.md', implode("\n", $lines));
        }
        return $groups;
    }

    // The modules in order, then any link groups from the course sidebar
    private function writeSidebar(Page $modulesPage, array $groups): void
    {
        $lines = [];
        foreach ($modulesPage->children as $module) {
            $lines[] = '- [' . $module->title() . '](' . $module->slug() . '.md)';
        }
        $this->writeFile('_sidebar.md', implode("\n", array_merge($lines, $groups)));
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
        $markdown = Links::mapToDocsify($markdown, $this->addresses, $gravPath);
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
