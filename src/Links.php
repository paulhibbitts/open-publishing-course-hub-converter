<?php
namespace OPG;

// Links within a guide. Grav: /guide/section/page. Docsify: guide/section/page.md, read from the site's top folder.
class Links
{
    // $single: a single-guide site, whose sections are top-level folders and whose guide home is home.md
    public static function toDocsify(string $markdown, string $guideSlug, bool $single): string
    {
        return preg_replace_callback('/\]\((\/[^)\s#?]*)\)/', function ($m) use ($guideSlug, $single) {
            $parts = array_values(array_filter(explode('/', $m[1]), 'strlen'));
            if (!$parts || $parts[0] !== $guideSlug) {
                return $m[0];
            }
            if ($single) {
                if (count($parts) === 1) {
                    return '](home.md)';
                }
                $parts = array_slice($parts, 1);
                return '](' . implode('/', $parts) . (count($parts) < 2 ? '/README.md' : '.md') . ')';
            }
            return '](' . implode('/', $parts) . (count($parts) < 3 ? '/README.md' : '.md') . ')';
        }, $markdown);
    }
}
