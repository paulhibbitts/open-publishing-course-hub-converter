<?php
namespace OPG;

// The guides of an Open Publishing Space site: an optional Guides page, then each guide
class Site
{
    public ?Page $guidesPage = null;
    public array $guides = [];
}
