<?php
namespace OPG;

// The guides of an Open Publishing Space site (an optional Guides page, then each guide), or the modules of an
// Open Course Hub course (its Modules page with a page per module, its menu pages and its sidebar)
class Site
{
    public ?Page $guidesPage = null;
    public array $guides = [];
    public ?Page $modulesPage = null;
    public array $coursePages = [];
    public ?Page $courseSidebar = null;
    public ?Page $courseHome = null;     // the course's home page; its children are the parts it shows (page-inject)
    public ?Page $courseFooter = null;
}
