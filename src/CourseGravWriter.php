<?php
namespace OPG;

// Writes a course as Open Course Hub pages: the home page and its parts, the Modules page with a folder per module,
// the menu pages, the course sidebar and the footer, each with its images beside it
class CourseGravWriter
{
    public function write(Site $site, string $out): void
    {
        $pageWriter = new GravWriter();
        $pages = [];
        if ($site->courseHome !== null) {
            $pages[] = $site->courseHome;
            $pages = array_merge($pages, $site->courseHome->children);
        }
        if ($site->modulesPage !== null) {
            $pages[] = $site->modulesPage;
            $pages = array_merge($pages, $site->modulesPage->children);
        }
        $pages = array_merge($pages, $site->coursePages);
        if ($site->courseSidebar !== null) {
            $pages[] = $site->courseSidebar;
        }
        if ($site->courseFooter !== null) {
            $pages[] = $site->courseFooter;
        }
        foreach ($pages as $page) {
            $pageWriter->writePage($page, $out);
        }
    }
}
