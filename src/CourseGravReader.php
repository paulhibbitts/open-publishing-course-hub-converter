<?php
namespace OPG;

// Reads the modules of an Open Course Hub course from its user/pages folder: the Modules page (module.md) with a page
// per module (course-page.md), the home page and the parts it shows, the menu pages (Schedule, Topics, Resources …),
// the course sidebar and the footer. Other pages – the blog and other page types – aren't part of the modules, so
// they're listed in $notes.
class CourseGravReader extends GravReader
{
    public array $notes = [];

    // the page types (Grav file names) converted as menu pages (course-page.md in Open Course Space and Helios
    // Course Hub courses)
    private const MENU_PAGE_FILES = ['page.md', 'default.md', 'course-page.md'];

    public function read(string $pagesDir): Site
    {
        $site = new Site();
        $this->notes = [];
        // a course in its own folder (Open Course Space or Open MultiCourse Hub): only its pages are converted
        foreach (['course.md', 'subsite.md'] as $courseFile) {
            if (is_file($pagesDir . '/' . $courseFile)) {
                $this->notes[] = 'This course is in its own folder (' . basename($pagesDir) . '); converted back to Grav, its pages '
                    . 'go back in that folder, beside its ' . $courseFile;
            }
        }
        foreach ($this->subfolders($pagesDir) as $name) {
            $folder = $pagesDir . '/' . $name;
            $file = $this->markdownFile($folder);
            if ($file === '') {
                continue;
            }
            if ($file === 'module.md') {
                $site->modulesPage = $this->readModules($pagesDir, $name);
                continue;
            }
            if ($name === 'sidebar') {
                $site->courseSidebar = $this->readPage($pagesDir, $name, $file, 'sidebar');
                continue;
            }
            if ($name === 'footer') {
                $site->courseFooter = $this->readPage($pagesDir, $name, $file, 'footer');
                continue;
            }
            // the skeleton's Read Me page is about setting up the Grav site (Git Sync, the Admin panel), not the course
            if (Helpers::slugFromFolder($name) === 'readme') {
                $page = $this->readPage($pagesDir, $name, $file, 'menu');
                $this->notes[] = $page->title() . ' (' . $name . ') is about the Grav site, so it wasn\'t converted';
                continue;
            }
            if (Helpers::slugFromFolder($name) === 'home') {
                $site->courseHome = $this->readHome($pagesDir, $name, $file);
                continue;
            }
            $page = $this->readPage($pagesDir, $name, $file, 'menu');
            if ($this->isMenuPage($name, $file, $page)) {
                $site->coursePages[] = $page;
            } elseif (Helpers::isNumbered($name) && ($page->meta['routable'] ?? true) !== false) {
                $this->notes[] = $page->title() . ' (' . $name . ') isn\'t part of the modules, so it wasn\'t converted';
            }
        }
        return $site;
    }

    // The Modules page and its module pages, in Grav's order
    private function readModules(string $pagesDir, string $modulesFolder): Page
    {
        $modulesPage = $this->readPage($pagesDir, $modulesFolder, 'module.md', 'modules');
        foreach ($this->subfolders($pagesDir . '/' . $modulesFolder) as $name) {
            $folder = $modulesFolder . '/' . $name;
            $file = $this->markdownFile($pagesDir . '/' . $folder);
            if ($file !== '' && Helpers::isNumbered($name)) {
                $modulesPage->children[] = $this->readPage($pagesDir, $folder, $file, 'module');
            }
        }
        return $modulesPage;
    }

    // The home page and the parts it shows with [plugin:page-inject](/home/_part) – an announcement, this week's
    // reminders, next week's preparations – in the order it shows them
    private function readHome(string $pagesDir, string $homeFolder, string $file): Page
    {
        $home = $this->readPage($pagesDir, $homeFolder, $file, 'home');
        foreach ($this->subfolders($pagesDir . '/' . $homeFolder) as $name) {
            $partFile = $this->markdownFile($pagesDir . '/' . $homeFolder . '/' . $name);
            if ($partFile !== '') {
                $home->children[] = $this->readPage($pagesDir, $homeFolder . '/' . $name, $partFile, 'home-part');
            }
        }
        return $home;
    }

    // A numbered, visible page of a plain page type (not the modular home page, the blog or other page types)
    private function isMenuPage(string $name, string $file, Page $page): bool
    {
        $isVisible = ($page->meta['visible'] ?? true) !== false && ($page->meta['routable'] ?? true) !== false;
        return Helpers::isNumbered($name) && in_array($file, self::MENU_PAGE_FILES, true) && $isVisible;
    }
}
