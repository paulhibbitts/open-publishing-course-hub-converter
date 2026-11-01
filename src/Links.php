<?php
namespace OPG;

// Links between guide pages. Grav: /guide/section/page (or /guide/section/page#heading).
// Docsify: guide/section/page.md (or guide/section/page.md?id=heading), read from the site's top folder.
class Links
{
    // $guideSlugs: every guide being written, so links between guides are converted too
    // $single: a single-guide site, whose sections are top-level folders and whose guide home is home.md
    public static function toDocsify(string $markdown, array $guideSlugs, bool $single): string
    {
        return preg_replace_callback('/\]\((\/[^)\s#?]*)(#[^)\s?]*)?\)/', function ($m) use ($guideSlugs, $single) {
            $parts = array_values(array_filter(explode('/', $m[1]), 'strlen'));
            if (!$parts || !in_array($parts[0], $guideSlugs, true)) {
                // not a guide page (the blog, an About page …): see gravOnly()
                return $m[0];
            }
            // Docsify jumps to a heading with ?id= instead of #
            $anchor = isset($m[2]) && $m[2] !== '' ? '?id=' . substr($m[2], 1) : '';
            if ($single) {
                if (count($parts) === 1) {
                    return '](home.md' . $anchor . ')';
                }
                $parts = array_slice($parts, 1);
                return '](' . implode('/', $parts) . (count($parts) < 2 ? '/README.md' : '.md') . $anchor . ')';
            }
            return '](' . implode('/', $parts) . (count($parts) < 3 ? '/README.md' : '.md') . $anchor . ')';
        }, $markdown);
    }

    // Links that only work on the Grav site: pages that aren't guides (About, the blog), Grav views such as
    // ?embedded=true, and relative links such as ../readme. With the original site's address they become full
    // links to it; without one they're left as they are and listed in $warnings.
    // $pagePath: the page's own address on the Grav site, e.g. /open-education-essentials (relative links start there)
    public static function gravOnly(string $markdown, string $pagePath, string $siteUrl, string $where, array &$warnings): string
    {
        $siteUrl = rtrim($siteUrl, '/');
        return preg_replace_callback('/(!?)\[([^\]]*)\]\(((?:\/|\.\.?\/)[^)\s]*)\)/', function ($m) use ($pagePath, $siteUrl, $where, &$warnings) {
            $url = $m[3];
            if (!str_starts_with($url, '/')) {
                $url = self::resolve($pagePath, $url);
            }
            if ($siteUrl === '') {
                $warnings[] = $where . ': "' . $m[3] . '" only works on the Grav site (add the original site address to link to it there)';
                return $m[0];
            }
            return $m[1] . '[' . $m[2] . '](' . $siteUrl . $url . ')';
        }, $markdown);
    }

    // ../readme from /open-education-essentials → /readme (like a browser, the last part of the address is the page)
    private static function resolve(string $pagePath, string $relative): string
    {
        $parts = array_values(array_filter(explode('/', $pagePath), 'strlen'));
        array_pop($parts);
        foreach (explode('/', $relative) as $piece) {
            if ($piece === '..') {
                array_pop($parts);
            } elseif ($piece !== '.' && $piece !== '') {
                $parts[] = $piece;
            }
        }
        return '/' . implode('/', $parts);
    }
}
