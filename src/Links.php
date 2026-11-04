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
        // ](/path) or ](/path#heading), maybe followed by a "title" or an option such as ':target=_blank'
        // (not //example.org, an address without https:)
        $pattern = '/\]\((\/(?!\/)[^)\s#?]*)(#[^)\s?]*)?( "[^"]*"| \'[^\']*\')?\)/';
        return preg_replace_callback($pattern, function ($match) use ($guideSlugs, $single) {
            $anchor = '';
            if (isset($match[2])) {
                $anchor = $match[2];
            }
            $option = '';
            if (isset($match[3])) {
                $option = $match[3];
            }
            $docsifyPath = self::docsifyPath($match[1], $anchor, $guideSlugs, $single);
            if ($docsifyPath === '') {
                // not a guide page (the blog, an About page …): see gravOnly()
                return $match[0];
            }
            return '](' . $docsifyPath . $option . ')';
        }, $markdown);
    }

    // '/guide/section/page' + '#heading' → 'guide/section/page.md?id=heading' ('' when it isn't a guide page)
    private static function docsifyPath(string $gravPath, string $anchor, array $guideSlugs, bool $single): string
    {
        $parts = self::pathParts($gravPath);
        if (count($parts) === 0 || !in_array($parts[0], $guideSlugs, true)) {
            return '';
        }
        // a page's Markdown version (/guide/page.md) is a Grav feature, not a guide page
        if (str_ends_with($gravPath, '.md')) {
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

    // Links to pages listed in $map (a Grav address without its ending / => its Docsify file, e.g. '/modules/module-01'
    // => 'module-01.md'), as for a course, where module pages and menu pages all sit at the site's top
    // $pagePath: the page's own Grav address, which relative links (../modules/module-02) start from
    // $originals: each converted link as [its Docsify address, its Grav address as written], so the way back can give
    // the same Grav address again (../modules/module-02 and /modules/module-02 are the same Docsify address)
    public static function mapToDocsify(string $markdown, array $map, string $pagePath, ?array &$originals = null): string
    {
        $pattern = '/\]\(((?:\/(?!\/)|\.\.?\/)[^)\s#?]*)(#[^)\s?]*)?( "[^"]*"| \'[^\']*\')?\)/';
        return preg_replace_callback($pattern, function ($match) use ($map, $pagePath, &$originals) {
            $url = $match[1];
            if (!str_starts_with($url, '/')) {
                $url = self::resolve($pagePath, $url);
            }
            $path = '/' . implode('/', self::pathParts($url));
            if (!isset($map[$path])) {
                // not a converted page (the blog, …): see gravOnly()
                return $match[0];
            }
            $anchor = '';
            if (isset($match[2]) && $match[2] !== '') {
                $anchor = '?id=' . substr($match[2], 1);
            }
            // kept only when written differently from the usual address (e.g. ../modules/module-02)
            $heading = $match[2] ?? '';
            $written = $match[1] . $heading;
            $usualAddress = array_search($map[$path], $map, true) . $heading;
            if ($originals !== null && $written !== $usualAddress) {
                $originals[] = [$map[$path] . $anchor, $written];
            }
            return '](' . $map[$path] . $anchor . ($match[3] ?? '') . ')';
        }, $markdown);
    }

    // The way back for a course: links to its Docsify pages (module-01.md, module-01.md?id=heading, or module-01
    // without .md) → their Grav address. $originals (from mapToDocsify) gives a link its Grav address as it was
    // written, as long as it still goes to the same page; other links get the page's address from $addresses
    // (Docsify file => Grav address).
    public static function mapToGrav(string $markdown, array $addresses, array $originals = []): string
    {
        // ](page) or ](page.md?id=heading), maybe with a "title" or ':option'
        $pattern = '/\]\(((?:\.\/)?[A-Za-z0-9_\/-]+(?:\.[A-Za-z0-9_-]+)*?)(\.md)?(\?id=[^)\s]*)?( "[^"]*"| \'[^\']*\')?\)/';
        return preg_replace_callback($pattern, function ($match) use ($addresses, &$originals) {
            $file = preg_replace('/^\.\//', '', $match[1]) . '.md';
            if (!isset($addresses[$file])) {
                return $match[0];
            }
            $docsifyAddress = $file . ($match[3] ?? '');
            $option = $match[4] ?? '';
            foreach ($originals as $index => $original) {
                if ($original[0] === $docsifyAddress) {
                    unset($originals[$index]);
                    return '](' . $original[1] . $option . ')';
                }
            }
            $anchor = '';
            if (isset($match[3]) && $match[3] !== '') {
                $anchor = '#' . substr($match[3], strlen('?id='));
            }
            return '](' . $addresses[$file] . $anchor . $option . ')';
        }, $markdown);
    }

    // Links that only work on the Grav site: pages that aren't guides (About, the blog), Grav views such as
    // ?embedded=true, and relative links such as ../readme. With the original site's address they become full
    // links to it; without one they're left as they are and listed in $warnings.
    // $pagePath: the page's own address on the Grav site, e.g. /open-education-essentials (relative links start there)
    public static function gravOnly(string $markdown, string $pagePath, string $siteUrl, string $where, array &$warnings): string
    {
        $siteUrl = rtrim($siteUrl, '/');
        // [text](/path…), [text](../path…) and [text](./path…), images included, maybe followed by a "title" or an
        // option such as ':target=_blank' (not //example.org, an address without https:)
        $pattern = '/(!?)\[([^\]]*)\]\(((?:\/(?!\/)|\.\.?\/)[^)\s]*)( "[^"]*"| \'[^\']*\')?\)/';
        return preg_replace_callback($pattern, function ($match) use ($pagePath, $siteUrl, $where, &$warnings) {
            $url = $match[3];
            $option = '';
            if (isset($match[4])) {
                $option = $match[4];
            }
            if (!str_starts_with($url, '/')) {
                $url = self::resolve($pagePath, $url);
            }
            if ($siteUrl === '') {
                $warnings[] = $where . ': "' . $match[3] . '" only works on the Grav site (add the original site address to link to it there)';
                return $match[0];
            }
            return $match[1] . '[' . $match[2] . '](' . $siteUrl . $url . $option . ')';
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
    // $keepSiteLinks: in a site this converter made, a link starting with / (such as /guide/page.md, a page's
    // Markdown version) is one it kept for the Grav site, so it's left as it is.
    public static function toGrav(string $markdown, string $singleGuide, string $siteUrl, bool $keepSiteLinks = false): string
    {
        // ](some/file.md) or ](some/file.md?id=heading) – a relative link to a Docsify page – maybe followed by a
        // "title" or an option such as ':target=_blank' (see newTabToGrav)
        $pattern = '/\]\(([A-Za-z0-9._\/-]+\.md)(\?id=[^)\s]*)?( "[^"]*"| \'[^\']*\')?\)/';
        $markdown = preg_replace_callback($pattern, function ($match) use ($singleGuide, $keepSiteLinks) {
            if ($keepSiteLinks && str_starts_with($match[1], '/')) {
                return $match[0];
            }
            $anchor = '';
            if (isset($match[2]) && $match[2] !== '') {
                $anchor = '#' . substr($match[2], strlen('?id='));
            }
            $option = '';
            if (isset($match[3])) {
                $option = $match[3];
            }
            return '](' . self::gravPath($match[1], $singleGuide) . $anchor . $option . ')';
        }, $markdown);

        if ($siteUrl !== '') {
            $markdown = str_replace('](' . rtrim($siteUrl, '/') . '/', '](/', $markdown);
        }
        return $markdown;
    }

    // 'guide/section/page.md' → '/guide/section/page', 'guide/README.md' → '/guide', 'home.md' → '/'
    private static function gravPath(string $docsifyPath, string $singleGuide): string
    {
        // a link from the site's top (/topic-one.md) is the same page as topic-one.md
        $parts = explode('/', ltrim($docsifyPath, '/'));
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

    // ---- opening links in a new tab ----

    // Grav: [text](https://example.org/?target=_blank#part) → Docsify: [text](https://example.org/#part ':target=_blank')
    public static function newTabToDocsify(string $markdown): string
    {
        return preg_replace('/\]\(([^)\s?]+)\?target=_blank(#[^)\s]*)?\)/', '](\1\2 \':target=_blank\')', $markdown);
    }

    // Docsify: [text](https://example.org/#part ':target=_blank') → Grav: [text](https://example.org/?target=_blank#part)
    public static function newTabToGrav(string $markdown): string
    {
        // the link's address, and any #part of it, then the option in single or double quotes
        return preg_replace('/\]\(([^)\s#]+)(#[^)\s]*)? [\'"]:target=_blank[\'"]\)/', '](\1?target=_blank\2)', $markdown);
    }

    // Docsify's image and link options → Grav's: ![alt](photo.jpg ':no-zoom :class=wide') → ![alt](photo.jpg?classes=wide),
    // [Quiz](https://example.org ':class=button') → [Quiz](https://example.org?classes=button).
    // The kits' banner images become an image Grav crops to a wide strip, which works in any theme:
    // ![alt](photo.jpg ':class=banner-image') → ![alt](photo.jpg?cropZoom=1200,300). Grav has nothing like :no-zoom,
    // so it's dropped.
    public static function imageOptionsToGrav(string $markdown): string
    {
        $pattern = '/(!?\[[^\]]*\]\()([^)\s]+) ([\'"])([^\'")]*:(?:no-zoom|class=)[^\'")]*)\3\)/';
        return preg_replace_callback($pattern, function ($match) {
            $isImage = str_starts_with($match[1], '!');
            $options = [];
            foreach (preg_split('/\s+/', trim($match[4])) as $option) {
                if (!str_starts_with($option, ':class=')) {
                    continue;
                }
                $class = substr($option, strlen(':class='));
                if ($isImage && isset(self::BANNER_CROPS[$class])) {
                    $options[] = 'cropZoom=' . self::BANNER_CROPS[$class];
                } else {
                    $options[] = 'classes=' . $class;
                }
            }
            $source = $match[2];
            foreach ($options as $option) {
                // add to the address's ?query if it already has one
                $separator = '?';
                if (str_contains($source, '?')) {
                    $separator = '&';
                }
                $source .= $separator . $option;
            }
            return $match[1] . $source . ')';
        }, $markdown);
    }

    // the kits' banner image classes, and the width and height Grav crops them to (about the kits' banner shapes)
    public const BANNER_CROPS = ['banner-image' => '1200,300', 'banner-tall-image' => '1200,420'];
}
