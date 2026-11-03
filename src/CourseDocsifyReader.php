<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads a Docsify course back into Open Course Hub pages. A course written by this converter carries hidden notes
// with each page's Grav file and settings, so it goes back unchanged (titles, descriptions and text edited in Docsify
// included). A course made in Docsify, laid out like the Docsify Open Course Starter Kit (home.md, a page per module
// in _sidebar.md, menu pages in _navbar.md, contact.md, _footer.md, images/), is turned into Open Course Hub pages.
class CourseDocsifyReader
{
    // things worth checking, for conversion-notes.txt
    public array $notes = [];

    private string $dir;
    // each Docsify page => its Grav address, for links between pages
    private array $addresses = [];
    // the modules and link groups listed in _sidebar.md, and the pages in _navbar.md
    private array $sidebar = ['modules' => [], 'groups' => []];
    private array $navbar = [];

    public function read(string $dir): Site
    {
        $this->dir = rtrim($dir, '/');
        $this->notes = [];
        $this->sidebar = $this->parseSidebar($this->readFile('_sidebar.md'));
        $this->navbar = $this->parseNavbar($this->readFile('_navbar.md'));

        $notedPages = [];
        foreach (glob($this->dir . '/*.md') as $path) {
            $note = Helpers::readGravNote((string)file_get_contents($path));
            if ($note !== null) {
                $notedPages[basename($path)] = $note;
            }
        }
        if (count($notedPages) > 0) {
            return $this->fromNotes($notedPages);
        }
        return $this->fromDocsify();
    }

    // ---- a course written by this converter ----

    private function fromNotes(array $notedPages): Site
    {
        $site = new Site();
        // every page's Grav address first, for the links between them
        foreach ($notedPages as $file => $note) {
            $gravFile = $note['where']['file'];
            if (basename($gravFile) === 'course-page.md') {
                $this->addresses[$file] = '/' . Helpers::slugFromFolder(basename(dirname(dirname($gravFile)))) . '/' . Helpers::slugFromFolder(basename(dirname($gravFile)));
            } elseif ($file === 'home.md') {
                $this->addresses[$file] = '/';
            } elseif (!str_contains(dirname($gravFile), '/') && Helpers::isNumbered(dirname($gravFile))) {
                $this->addresses[$file] = '/' . Helpers::slugFromFolder(dirname($gravFile));
            }
        }

        $modules = [];
        foreach ($notedPages as $file => $note) {
            $gravFile = $note['where']['file'];
            if (basename($gravFile) === 'module.md') {
                // the Modules page's note: in _sidebar.md, or in home.md when the course has no home page
                $site->modulesPage = $this->notedPage('modules', $note, '', []);
                $site->modulesPage->body = $note['where']['body'] ?? '';
                continue;
            }
            if ($file === 'home.md') {
                $site->courseHome = $this->readNotedHome($note);
                continue;
            }
            if (basename($gravFile) === 'course-page.md') {
                $modules[$gravFile] = $this->readNotedModule($file, $note);
                continue;
            }
            $kind = 'menu';
            if (str_starts_with($gravFile, 'sidebar/')) {
                $kind = 'sidebar';
            } elseif (str_starts_with($gravFile, 'footer/')) {
                $kind = 'footer';
            }
            $rest = $note['rest'];
            if ($kind === 'sidebar') {
                $rest = $this->withSidebarGroups($rest);
            }
            $page = $this->notedPage($kind, $note, $this->authorText($rest, $note), []);
            $this->addNotedImages($page, $note);
            if ($kind === 'menu') {
                $this->updateTitle($page, $note['rest'], $this->navbar[$file] ?? '');
                $site->coursePages[] = $page;
            } elseif ($kind === 'sidebar') {
                $site->courseSidebar = $page;
            } else {
                $site->courseFooter = $page;
            }
        }
        // the sidebar's link groups without a Contact page (all of the course sidebar was links)
        if ($site->courseSidebar === null && count($this->sidebar['groups']) > 0) {
            $site->courseSidebar = $this->sidebarPage($this->groupsText(), 'sidebar');
        }
        if ($site->modulesPage === null) {
            $site->modulesPage = $this->newPage('modules', '03.modules', 'module.md', ['title' => 'Modules']);
        }
        ksort($modules, SORT_NATURAL);
        $site->modulesPage->children = array_values($modules);
        usort($site->coursePages, fn($a, $b) => strnatcmp($a->folder, $b->folder));
        $this->noteOtherPages(array_keys($notedPages));
        return $site;
    }

    // A module: its settings, with the title from the sidebar and the description from the page's heading (both may
    // have been edited in Docsify), and its text
    private function readNotedModule(string $file, array $note): Page
    {
        $page = $this->notedPage('module', $note, $this->authorText($note['rest'], $note), []);
        $this->addNotedImages($page, $note);
        $changes = [];
        $label = $this->sidebar['modules'][$file] ?? '';
        if ($label !== '' && $label !== $page->title()) {
            $changes['title'] = $label;
        }
        if (preg_match('/<!-- grav:gen -->\n.*?^# (.+?)$.*?<!-- \/grav:gen -->/sm', $note['rest'], $found) === 1) {
            $heading = trim($found[1]);
            $shown = $page->setting('description') !== '' ? $page->setting('description') : $page->title();
            if ($page->setting('description') !== '' && $heading !== $shown) {
                $changes['description'] = $heading;
            } elseif ($page->setting('description') === '' && $heading !== $shown && !isset($changes['title'])) {
                $changes['title'] = $heading;
            }
            // a first heading in the text that repeated the old one (hidden in Docsify) repeats the new one
            if ($heading !== $shown) {
                $page->body = preg_replace('/^(\s*#{1,6}[ \t]+[*_]*)' . preg_quote($shown, '/') . '(?=[*_]*[ \t]*(\n|$))/', '${1}' . addcslashes($heading, '\\$'), $page->body, 1);
            }
        }
        $this->changeSettings($page, $changes);
        return $page;
    }

    // The home page and its parts: each part starts with its own note and the page-inject line that shows it, and
    // ends with <!-- grav:/part --> (or, in courses converted before that note was added, at the next part or the
    // end); the home page's own text is everything else
    private function readNotedHome(array $note): Page
    {
        $home = $this->notedPage('home', $note, '', []);
        $parts = [];
        $pattern = '/<!-- grav: \{.*?\n-->\n(.*?)(?:<!-- grav:\/part -->|(?=<!-- grav: \{)|$)/s';
        $ownText = preg_replace_callback($pattern, function ($match) use (&$parts) {
            $partNote = Helpers::readGravNote($match[0]);
            if ($partNote === null || preg_match('/<!-- grav-embed-line: (\[plugin:page-inject\]\([^)]*\)) -->/', $partNote['rest'], $inject) !== 1) {
                return $match[0];
            }
            $text = str_replace($inject[0], '', str_replace('<!-- grav:/part -->', '', $partNote['rest']));
            $part = $this->notedPage('home-part', $partNote, $this->authorText($text, $partNote), []);
            $this->addNotedImages($part, $partNote);
            $parts[] = $part;
            return "\n" . $inject[1] . "\n";
        }, $note['rest']);
        // the parts' blank lines are left behind where they were taken out
        $home->body = $this->authorText(preg_replace("/\n{3,}/", "\n\n", $ownText), $note);
        $this->updateTitle($home, $note['rest'], '');
        $home->children = $parts;
        return $home;
    }

    // A page from its note: its Grav file, its front matter exactly as it was, and its text
    private function notedPage(string $kind, array $note, string $body, array $images): Page
    {
        $gravFile = $note['where']['file'];
        $page = new Page($kind, dirname($gravFile), basename($gravFile));
        $page->yaml = $note['yaml'];
        $settings = Yaml::parse($note['yaml']);
        if (is_array($settings)) {
            $page->meta = $settings;
        }
        $page->body = $body;
        // the spacing around the text, when it wasn't the usual one
        if (isset($note['where']['before'])) {
            $page->textBefore = (string)$note['where']['before'];
        }
        if (isset($note['where']['after'])) {
            $page->textAfter = (string)$note['where']['after'];
        }
        return $page;
    }

    // The author's text: everything the converter didn't add, with callouts, embeds and links back in Grav's form
    private function authorText(string $rest, array $note): string
    {
        $text = trim(preg_replace('/<!-- grav:gen -->\n.*?\n<!-- \/grav:gen -->/s', '', $rest));
        $codeParts = [];
        $text = Helpers::hideCode($text, $codeParts);
        $text = Callouts::toGrav($text);
        $text = Embeds::toGrav($text);
        $text = Links::mapToGrav($text, $this->addresses, $note['where']['links'] ?? []);
        $site = $note['where']['site'] ?? '';
        if ($site !== '') {
            $text = str_replace('](' . rtrim($site, '/') . '/', '](/', $text);
        }
        $text = Links::newTabToGrav($text);
        return Helpers::showCode($text, $codeParts);
    }

    // A page's images (listed in its note) back from images/: images/photo.jpg, or images/module-01-photo.jpg when
    // another page had an image of the same name
    private function addNotedImages(Page $page, array $note): void
    {
        foreach ($note['where']['images'] ?? [] as $image) {
            $image = basename((string)$image);
            $candidates = ['images/' . $page->slug() . '-' . $image, 'images/' . $image];
            $chosen = '';
            foreach ($candidates as $candidate) {
                if (str_contains($page->body, '](' . $candidate)) {
                    $chosen = $candidate;
                    break;
                }
            }
            if ($chosen === '') {
                foreach ($candidates as $candidate) {
                    if (is_file($this->dir . '/' . $candidate)) {
                        $chosen = $candidate;
                        break;
                    }
                }
            }
            $source = $chosen === '' ? '' : Helpers::imageInside($this->dir, $this->dir . '/' . $chosen);
            if ($source === '') {
                continue;
            }
            $page->images[] = $image;
            $page->imageSources[$image] = $source;
            $page->body = str_replace('](' . $chosen, '](' . $image, $page->body);
        }
    }

    // The course sidebar's Contact page with each link group (from _sidebar.md) back in its place, laid out as it was
    private function withSidebarGroups(string $rest): string
    {
        $groups = $this->sidebar['groups'];
        $rest = preg_replace_callback('/<!-- grav:sidebar-group (\{.*?\}) -->/', function ($match) use (&$groups) {
            $group = array_shift($groups);
            if ($group === null) {
                return '';
            }
            $saved = json_decode($match[1], true);
            $layout = is_array($saved) ? (string)($saved['layout'] ?? '') : '';
            if ($layout === '' || substr_count($layout, '{link}') !== count($group['links'])) {
                return $this->groupText($group);
            }
            $layout = str_replace('{heading}', $group['heading'], $layout);
            foreach ($group['links'] as $link) {
                $at = strpos($layout, '{link}');
                $layout = substr($layout, 0, $at) . $link . substr($layout, $at + strlen('{link}'));
            }
            return $layout;
        }, $rest);
        // groups added in Docsify go at the end
        foreach ($groups as $group) {
            $rest .= "\n\n" . $this->groupText($group);
        }
        return $rest;
    }

    // A link group as Open Course Hub writes it: a heading, then a link on each line
    private function groupText(array $group): string
    {
        $lines = ['## ' . $group['heading']];
        foreach ($group['links'] as $link) {
            $lines[] = $link . '  ';
        }
        return rtrim(implode("\n", $lines));
    }

    private function groupsText(): string
    {
        $texts = [];
        foreach ($this->sidebar['groups'] as $group) {
            $texts[] = $this->groupText($group);
        }
        return implode("\n\n", $texts);
    }

    // A title edited in Docsify (the page's generated # heading, or its navbar link) goes into its settings
    private function updateTitle(Page $page, string $rest, string $navbarLabel): void
    {
        $changes = [];
        if (preg_match('/<!-- grav:gen -->\n# (.+?)\n<!-- \/grav:gen -->/', $rest, $found) === 1 && trim($found[1]) !== $page->title()) {
            $changes['title'] = trim($found[1]);
        }
        if ($navbarLabel !== '' && $navbarLabel !== $page->title() && !isset($changes['title'])) {
            $changes['title'] = $navbarLabel;
        }
        $this->changeSettings($page, $changes);
    }

    private function changeSettings(Page $page, array $changes): void
    {
        if (count($changes) === 0) {
            return;
        }
        foreach ($changes as $name => $value) {
            $page->meta[$name] = $value;
        }
        $page->yaml = $this->yaml($page->meta);
    }

    // ---- a course made in Docsify ----

    private function fromDocsify(): Site
    {
        $site = new Site();
        $moduleFiles = array_keys($this->sidebar['modules']);
        // module pages that aren't in the sidebar yet (a course often lists only the weeks so far)
        $unlisted = [];
        foreach (glob($this->dir . '/*.md') as $path) {
            $file = basename($path);
            if (preg_match('/^(module|week)-?[0-9]+\.md$/i', $file) === 1 && !in_array($file, $moduleFiles, true) && !isset($this->navbar[$file])) {
                $moduleFiles[] = $file;
                $unlisted[] = $file;
            }
        }
        if (count($unlisted) > 0) {
            $this->notes[] = implode(', ', $unlisted) . ' ' . (count($unlisted) === 1 ? 'isn\'t' : 'aren\'t')
                . ' in the sidebar, so ' . (count($unlisted) === 1 ? 'it\'s a hidden module' : 'they\'re hidden modules') . ' (they open from links, but aren\'t listed)';
        }
        $menuFiles = [];
        foreach ($this->navbar as $file => $label) {
            if ($file !== 'contact.md' && !in_array($file, $moduleFiles, true)) {
                $menuFiles[] = $file;
            }
        }

        // every page's Grav address first, for the links between them
        $this->addresses = ['home.md' => '/'];
        foreach ($moduleFiles as $file) {
            $this->addresses[$file] = '/modules/' . basename($file, '.md');
        }
        foreach ($menuFiles as $file) {
            $this->addresses[$file] = '/' . basename($file, '.md');
        }

        $site->courseHome = $this->homeFromDocsify();
        $site->modulesPage = $this->newPage('modules', '03.modules', 'module.md', ['title' => 'Modules', 'published' => true]);
        foreach ($moduleFiles as $index => $file) {
            $isListed = isset($this->sidebar['modules'][$file]);
            $site->modulesPage->children[] = $this->moduleFromDocsify($file, sprintf('%02d', $index + 1), $isListed);
        }
        foreach ($menuFiles as $index => $file) {
            $site->coursePages[] = $this->menuPageFromDocsify($file, sprintf('%02d', $index + 5));
        }

        // the course sidebar: the Contact page's details, then the sidebar's link groups (such as the LMS links)
        $contact = $this->readFile('contact.md');
        $contact = trim(preg_replace('/^\s*# .*\n/', '', $contact, 1));
        $sidebarText = trim($contact . "\n\n" . $this->groupsText());
        if ($sidebarText !== '') {
            $site->courseSidebar = $this->sidebarPage($sidebarText, 'contact.md');
        }
        $footer = trim($this->readFile('_footer.md'));
        if ($footer !== '') {
            $site->courseFooter = $this->newPage('footer', 'footer', 'default.md', ['title' => 'Footer', 'routable' => false, 'visible' => false]);
            $site->courseFooter->body = $this->toGravText($footer, $site->courseFooter, '_footer.md');
        }
        $this->noteOtherPages(array_merge(['home.md', 'contact.md'], $moduleFiles, $menuFiles));
        return $site;
    }

    // The course home: its text, with each announcement box (<blockquote class="announcement">) as a part of the home
    // page shown in an alert box, as Open Course Hub's reminders are
    private function homeFromDocsify(): Page
    {
        $home = $this->newPage('home', '01.home', 'page.md', ['title' => 'Home', 'published' => true, 'visible' => false, 'hide_page_title' => true]);
        $text = $this->readFile('home.md');
        $number = 0;
        $text = preg_replace_callback('/<blockquote class="announcement">\s*\n(.*?)\n\s*<\/blockquote>/s', function ($match) use ($home, &$number) {
            $slug = '_announcement' . ($number === 0 ? '' : '-' . ($number + 1));
            $part = $this->newPage('home-part', '01.home/' . sprintf('%02d', $number) . '.' . $slug, 'alert.md', [
                'title' => 'Announcement', 'alert_style' => 'alert-secondary', 'published' => true, 'visible' => false,
            ]);
            $part->body = $this->toGravText(trim($match[1]), $part, 'home.md');
            $home->children[] = $part;
            $number++;
            return '[plugin:page-inject](/home/' . $slug . ')';
        }, $text);
        $home->body = $this->toGravText(trim($text), $home, 'home.md');
        return $home;
    }

    // A module page: the banner image and its credit become the module's header image, the # heading its description
    // (its title is the sidebar's label), and a Learning Objectives box its learning objectives
    private function moduleFromDocsify(string $file, string $number, bool $isListed): Page
    {
        $slug = basename($file, '.md');
        $label = $this->sidebar['modules'][$file] ?? Helpers::titleFromSlug($slug);
        $settings = ['title' => $label];
        $lines = explode("\n", str_replace("\r\n", "\n", $this->readFile($file)));
        $i = $this->skipBlankLines($lines, 0);
        $headerImage = null;
        if (isset($lines[$i]) && preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\s+[\'"][^\'"]*:class=banner-image[^\'"]*[\'"]\)\s*$/', $lines[$i], $found) === 1) {
            $headerImage = $found;
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        if ($headerImage !== null && isset($lines[$i]) && preg_match('/^<small>Photo: (?:\[(.+?)\]\((.+?)\)|(.+?))<\/small>\s*$/', $lines[$i], $found) === 1) {
            $settings['header_image_credit'] = $found[1] !== '' ? $found[1] : ($found[3] ?? '');
            if ($found[2] !== '') {
                $settings['header_image_creditlink'] = $found[2];
            }
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        if (isset($lines[$i]) && str_starts_with($lines[$i], '# ')) {
            $heading = trim(substr($lines[$i], 2));
            if ($heading !== $label) {
                $settings['description'] = $heading;
            }
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        if (isset($lines[$i]) && isset($lines[$i + 1]) && str_starts_with($lines[$i], '> [!') && trim($lines[$i + 1]) === '> **Learning Objectives**') {
            $objectives = [];
            $j = $i + 2;
            while ($j < count($lines) && preg_match('/^>( |$)/', $lines[$j]) === 1) {
                $objectives[] = preg_replace('/^> ?/', '', $lines[$j]);
                $j++;
            }
            $settings['learning_objectives'] = trim(implode("\n", $objectives));
            $i = $this->skipBlankLines($lines, $j);
        }
        // a module not in the sidebar opens from links but isn't listed, as in Docsify
        if (!$isListed) {
            $settings['visible'] = false;
        }
        $page = $this->newPage('module', '03.modules/' . $number . '.' . $slug, 'course-page.md', $settings);
        $body = trim(implode("\n", array_slice($lines, $i)));
        if ($headerImage !== null) {
            // the header image goes beside the page like its other images; Open Matter shows it at the top
            $this->addImage($page, $headerImage[2], $file);
            $page->meta = array_merge($page->meta, ['header_image' => true, 'header_image_file' => basename($headerImage[2])]);
            if ($headerImage[1] !== '') {
                $page->meta['header_image_alt_text'] = $headerImage[1];
            }
        }
        $page->body = $this->toGravText($body, $page, $file);
        $page->yaml = $this->yaml($page->meta);
        return $page;
    }

    // A menu page (Schedule, Topics …): its navbar label as its title, and a # heading that repeats it left out (Grav
    // shows the title). A different heading becomes the title, with the label as its menu name.
    private function menuPageFromDocsify(string $file, string $number): Page
    {
        $label = $this->navbar[$file];
        $settings = ['title' => $label];
        $text = trim(str_replace("\r\n", "\n", $this->readFile($file)));
        if (preg_match('/^# (.+)\n?/', $text, $found) === 1) {
            if (trim($found[1]) !== $label) {
                $settings = ['title' => trim($found[1]), 'menu' => $label];
            }
            $text = trim(substr($text, strlen($found[0])));
        }
        $page = $this->newPage('menu', $number . '.' . basename($file, '.md'), 'page.md', $settings);
        $page->body = $this->toGravText($text, $page, $file);
        return $page;
    }

    private function sidebarPage(string $text, string $from): Page
    {
        $page = $this->newPage('sidebar', 'sidebar', 'default.md', ['title' => 'Sidebar', 'routable' => false, 'visible' => false]);
        $page->body = $this->toGravText($text, $page, $from);
        return $page;
    }

    // Docsify Markdown → Grav Markdown: callouts, embeds and icons, image and link options, links between the course's
    // pages, and the page's images (copied beside it)
    private function toGravText(string $markdown, Page $page, string $docsifyFile): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        $markdown = Callouts::toGrav($markdown);
        $markdown = Embeds::toGrav($markdown);
        $markdown = Embeds::iconsToGrav($markdown);
        $markdown = Embeds::iframesToGrav($markdown);
        $markdown = Links::imageOptionsToGrav($markdown);
        $markdown = Links::mapToGrav($markdown, $this->addresses);
        $markdown = Links::newTabToGrav($markdown);
        $markdown = Helpers::showCode($markdown, $codeParts);
        preg_match_all('/!\[[^\]]*\]\(([^)\s"\'?]+)/', $markdown, $found);
        foreach (array_unique($found[1]) as $source) {
            if (str_contains($source, '://') || str_starts_with($source, '//')) {
                continue;
            }
            if ($this->addImage($page, $source, $docsifyFile)) {
                $markdown = str_replace('](' . $source, '](' . basename($source), $markdown);
            }
        }
        return $markdown;
    }

    // Copies an image of the site beside the page; false (and a note) when it isn't in the site
    private function addImage(Page $page, string $source, string $docsifyFile): bool
    {
        $path = Helpers::imageInside($this->dir, $this->dir . '/' . ltrim($source, './'));
        if ($path === '') {
            $this->notes[] = $docsifyFile . ': image "' . $source . '" wasn\'t found in the site';
            return false;
        }
        $name = basename($source);
        if (!in_array($name, $page->images, true)) {
            $page->images[] = $name;
            $page->imageSources[$name] = $path;
        }
        return true;
    }

    private function newPage(string $kind, string $folder, string $file, array $settings): Page
    {
        $page = new Page($kind, $folder, $file);
        $page->meta = $settings;
        $page->yaml = $this->yaml($settings);
        return $page;
    }

    // ---- both ----

    // _sidebar.md: the modules (a link to a page of the site => its label) and the link groups (a bold or plain
    // heading with links under it, such as the LMS links). Links to other sites and HTML are noted.
    private function parseSidebar(string $text): array
    {
        $sidebar = ['modules' => [], 'groups' => []];
        $group = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            if (preg_match('/^\s*<(?!!--)[a-z]/i', $line) === 1) {
                $this->addNote('a sidebar has HTML (such as a form or button), which wasn\'t converted');
                continue;
            }
            if (preg_match('/^[-*] \[([^\]]+)\]\(([^)\s]+)\)\s*$/', $line, $found) === 1) {
                $file = $this->siteFile($found[2]);
                if ($file !== '') {
                    $sidebar['modules'][$file] = $found[1];
                } else {
                    $this->addNote('the sidebar link to "' . $found[1] . '" isn\'t a page of the course, so it wasn\'t converted');
                }
                $group = null;
            } elseif (preg_match('/^[-*] (?:\*\*)?([^*\[]+?)(?:\*\*)?\s*$/', $line, $found) === 1) {
                $sidebar['groups'][] = ['heading' => $found[1], 'links' => []];
                $group = count($sidebar['groups']) - 1;
            } elseif ($group !== null && preg_match('/^\s+[-*] (\[.+\]\([^)]+\))\s*$/', $line, $found) === 1) {
                $sidebar['groups'][$group]['links'][] = $found[1];
            }
        }
        // a group without any links isn't kept
        $sidebar['groups'] = array_values(array_filter($sidebar['groups'], fn($g) => count($g['links']) > 0));
        return $sidebar;
    }

    // _navbar.md: a link to a page of the site => its label (links to other sites are noted)
    private function parseNavbar(string $text): array
    {
        $navbar = [];
        preg_match_all('/^\s*[-*] \[([^\]]+)\]\(([^)\s]+)\)/m', $text, $found, PREG_SET_ORDER);
        foreach ($found as $match) {
            $file = $this->siteFile($match[2]);
            if ($file !== '') {
                $navbar[$file] = $match[1];
            } else {
                $this->addNote('the navbar link to "' . $match[1] . '" isn\'t a page of the course, so it wasn\'t converted');
            }
        }
        return $navbar;
    }

    // 'module-01', './module-01.md' or 'module-01.md?id=x' → 'module-01.md' when that page is in the site, else ''
    private function siteFile(string $target): string
    {
        if (preg_match('/^([a-z]+:|\/\/|#)/i', $target) === 1) {
            return '';
        }
        $file = ltrim(preg_replace('/[?#].*$/', '', $target), '/');
        $file = preg_replace('/^\.\//', '', $file);
        if ($file === '' || str_contains($file, '/')) {
            return '';
        }
        if (!str_ends_with($file, '.md')) {
            $file .= '.md';
        }
        return is_file($this->dir . '/' . $file) ? $file : '';
    }

    // Pages of the site that weren't converted (pages for the LMS, a cover page …)
    private function noteOtherPages(array $converted): void
    {
        $special = ['_sidebar.md', '_navbar.md', '_footer.md', 'README.md'];
        foreach (glob($this->dir . '/*.md') as $path) {
            $file = basename($path);
            if (in_array($file, $converted, true) || in_array($file, $special, true)) {
                continue;
            }
            if (in_array($file, ['_coverpage.md', 'coverpage.md'], true)) {
                $this->addNote($file . ' (Docsify\'s cover page) isn\'t used in Grav, so it wasn\'t converted');
                continue;
            }
            $this->addNote($file . ' isn\'t in the sidebar or navbar, so it wasn\'t converted');
        }
    }

    private function addNote(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    private function readFile(string $file): string
    {
        $path = $this->dir . '/' . $file;
        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    private function skipBlankLines(array $lines, int $i): int
    {
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }
        return $i;
    }

    private function yaml(array $settings): string
    {
        return rtrim(Yaml::dump($settings, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }
}
