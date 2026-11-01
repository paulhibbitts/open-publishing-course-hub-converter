<?php
namespace OPG;

// Links between guide pages. Grav: /guide/section/page (or /guide/section/page#heading).
// Docsify: guide/section/page.md (or guide/section/page.md?id=heading), read from the site's top folder.
class Links
{
    // ---- Grav → Docsify ----

    // $guideSlugs: every guide being written, so links between guides are converted too
    // $single: a single-guide site, whose sections are top-level folders and whose guide home is home.md
    public static function toDocsify(string $markdown, array $guideSlugs, bool $single): string
    {
        // ](/path) or ](/path#heading)
        $pattern = '/\]\((\/[^)\s#?]*)(#[^)\s?]*)?\)/';
        return preg_replace_callback($pattern, function ($match) use ($guideSlugs, $single) {
            $anchor = '';
            if (isset($match[2])) {
                $anchor = $match[2];
            }
            $docsifyPath = self::docsifyPath($match[1], $anchor, $guideSlugs, $single);
            if ($docsifyPath === '') {
                // not a guide page (the blog, an About page …): see gravOnly()
                return $match[0];
            }
            return '](' . $docsifyPath . ')';
        }, $markdown);
    }

    // '/guide/section/page' + '#heading' → 'guide/section/page.md?id=heading' ('' when it isn't a guide page)
    private static function docsifyPath(string $gravPath, string $anchor, array $guideSlugs, bool $single): string
    {
        $parts = self::pathParts($gravPath);
        if (count($parts) === 0 || !in_array($parts[0], $guideSlugs, true)) {
            return '';
        }
        // Docsify jumps to a heading with ?id= instead of #
        $docsifyAnchor = '';
        if ($anchor !== '') {
            $docsifyAnchor = '?id=' . substr($anchor, 1);
        }

        if ($single) {
            // the guide's own address is the site's home page; its sections are top-level folders
            if (count($parts) === 1) {
                return 'home.md' . $docsifyAnchor;
            }
            array_shift($parts);
            if (count($parts) === 1) {
                return $parts[0] . '/README.md' . $docsifyAnchor;
            }
            return implode('/', $parts) . '.md' . $docsifyAnchor;
        }

        // a guide or a section is a folder with a README.md; a page is its own file
        if (count($parts) < 3) {
            return implode('/', $parts) . '/README.md' . $docsifyAnchor;
        }
        return implode('/', $parts) . '.md' . $docsifyAnchor;
    }

    // Links that only work on the Grav site: pages that aren't guides (About, the blog), Grav views such as
    // ?embedded=true, and relative links such as ../readme. With the original site's address they become full
    // links to it; without one they're left as they are and listed in $warnings.
    // $pagePath: the page's own address on the Grav site, e.g. /open-education-essentials (relative links start there)
    public static function gravOnly(string $markdown, string $pagePath, string $siteUrl, string $where, array &$warnings): string
    {
        $siteUrl = rtrim($siteUrl, '/');
        // [text](/path…), [text](../path…) and [text](./path…), images included
        $pattern = '/(!?)\[([^\]]*)\]\(((?:\/|\.\.?\/)[^)\s]*)\)/';
        return preg_replace_callback($pattern, function ($match) use ($pagePath, $siteUrl, $where, &$warnings) {
            $url = $match[3];
            if (!str_starts_with($url, '/')) {
                $url = self::resolve($pagePath, $url);
            }
            if ($siteUrl === '') {
                $warnings[] = $where . ': "' . $match[3] . '" only works on the Grav site (add the original site address to link to it there)';
                return $match[0];
            }
            return $match[1] . '[' . $match[2] . '](' . $siteUrl . $url . ')';
        }, $markdown);
    }

    // ../readme from /open-education-essentials → /readme (as in a browser, the last part of the address is the page)
    private static function resolve(string $pagePath, string $relative): string
    {
        $parts = self::pathParts($pagePath);
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

    // '/guide/section/' → ['guide', 'section']
    private static function pathParts(string $path): array
    {
        $parts = [];
        foreach (explode('/', $path) as $piece) {
            if ($piece !== '') {
                $parts[] = $piece;
            }
        }
        return $parts;
    }

    // ---- Docsify → Grav ----

    // guide/section/page.md?id=heading → /guide/section/page#heading, guide/README.md → /guide,
    // home.md → / (or, in a single guide, the guide's address). Links to the original site go back to Grav's own
    // form (/about). $singleGuide: the single guide's address name, or '' for a site with a Guides page.
    public static function toGrav(string $markdown, string $singleGuide, string $siteUrl): string
    {
        // ](some/file.md) or ](some/file.md?id=heading) – a relative link to a Docsify page
        $pattern = '/\]\(([A-Za-z0-9._\/-]+\.md)(\?id=[^)\s]*)?\)/';
        $markdown = preg_replace_callback($pattern, function ($match) use ($singleGuide) {
            $anchor = '';
            if (isset($match[2]) && $match[2] !== '') {
                $anchor = '#' . substr($match[2], strlen('?id='));
            }
            return '](' . self::gravPath($match[1], $singleGuide) . $anchor . ')';
        }, $markdown);

        if ($siteUrl !== '') {
            $markdown = str_replace('](' . rtrim($siteUrl, '/') . '/', '](/', $markdown);
        }
        return $markdown;
    }

    // 'guide/section/page.md' → '/guide/section/page', 'guide/README.md' → '/guide', 'home.md' → '/'
    private static function gravPath(string $docsifyPath, string $singleGuide): string
    {
        $parts = explode('/', $docsifyPath);
        $fileName = array_pop($parts);
        if ($fileName !== 'README.md' && $fileName !== 'home.md') {
            $parts[] = substr($fileName, 0, -strlen('.md'));
        }

        if ($fileName === 'home.md' && count($parts) === 0) {
            if ($singleGuide !== '') {
                return '/' . $singleGuide;
            }
            return '/';
        }
        if ($singleGuide !== '') {
            array_unshift($parts, $singleGuide);
        }
        return '/' . implode('/', $parts);
    }
}
