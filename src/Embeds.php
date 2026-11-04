<?php
namespace OPG;

// Embed shortcodes (Grav) ↔ HTML that Docsify shows as it is. Each embed keeps its shortcode in a hidden note on the
// line above it, so the way back gives the exact shortcode again.
//   [googleslides title="…"]address[/googleslides], [iframe url="…"/], [youtube]address[/youtube] → a responsive
//     iframe, as in the Docsify course template (<div class="video-container-16by9"><iframe …></iframe></div>)
//   [embedly]address[/embedly], [linkpreviewcard url="…"] → an Embedly card (<a class="embedly-card" …>), which
//     Docsify-This and the kits load
//   [pdf title="…"]address[/pdf] → a link
//   ![alt](image.jpg?cropZoom=800,300) – an image Grav crops to a wide banner → the kits' banner image
//     (![alt](image.jpg ':class=banner-image')), as Docsify ignores Grav's image options
//   [safe-email …]address[/safe-email] → an email link
//   [button label="…" url="…" /] → a link button, as in the Starter Kits ([label](url ':class=button'))
//   [h5p url="…" /] → an H5P iframe and its resizer script, as in the Starter Kits
//   [fa="calendar-o"][/fa] → a Font Awesome icon in Docsify-This's own form (:far fa-calendar fa-fw:), kept on its
//     line, since icons sit inside headings and links
class Embeds
{
    private const NOTE = 'grav-embed';

    // ---- Grav → Docsify ----

    public static function toDocsify(string $markdown): string
    {
        $markdown = preg_replace_callback('/\[fa=?"?([a-z0-9-]+)"?[^\]]*\](?:\[\/fa\])?/', [self::class, 'icon'], $markdown);
        $patterns = [
            '/\[googleslides((?: [^\]]*)?)\](.*?)\[\/googleslides\]/' => 'slides',
            '/\[youtube((?: [^\]]*)?)\](.*?)\[\/youtube\]/' => 'youtube',
            '/\[iframe((?: [^\]]*)?)\s*\/\]/' => 'iframe',
            '/\[embedly((?: [^\]]*)?)\](.*?)\[\/embedly\]/' => 'embedly',
            '/\[pdf((?: [^\]]*)?)\](.*?)\[\/pdf\]/' => 'pdf',
            '/\[linkpreviewcard((?: [^\]]*)?)\s*\/?\]/' => 'linkpreview',
            '/\[safe-email((?: [^\]]*)?)\](.*?)\[\/safe-email\]/' => 'email',
            '/\[button((?: [^\]]*)?)\s*\/\]/' => 'button',
            '/\[h5p((?: [^\]]*)?)\s*\/\]/' => 'h5p',
            '/!\[([^\]]*)\]\(([^)\s?]+\?cropZoom=[0-9]+,[0-9]+)\)/' => 'banner',
        ];
        foreach ($patterns as $pattern => $kind) {
            $text = $markdown;
            // PREG_OFFSET_CAPTURE (below): each match is [text, position]
            $markdown = preg_replace_callback($pattern, function ($match) use ($kind, $text) {
                $address = '';
                if (isset($match[2])) {
                    $address = trim($match[2][0]);
                }
                if ($kind === 'banner') {
                    $html = self::banner($match[1][0], $address);
                } else {
                    $html = self::html($kind, self::attributes($match[1][0]), $address);
                }
                if ($html === '') {
                    return $match[0][0];
                }
                // inside a > quote (a callout), the note and the HTML keep the > so they stay in the box
                $quote = '';
                if (self::isInQuote($text, $match[0][1])) {
                    $quote = '> ';
                }
                // the note and the HTML each on a line of their own, as Docsify shows HTML that starts a line
                return "\n" . $quote . '<!-- ' . self::NOTE . ': ' . $match[0][0] . " -->\n" . $quote . $html . "\n" . $quote;
            }, $markdown, -1, $count, PREG_OFFSET_CAPTURE);
        }
        return $markdown;
    }

    // Whether $position is on a line starting with > (a quote or callout)
    private static function isInQuote(string $text, int $position): bool
    {
        $before = substr($text, 0, $position);
        $lineStart = strrpos($before, "\n");
        if ($lineStart === false) {
            $lineStart = -1;
        }
        return substr($text, $lineStart + 1, 1) === '>';
    }

    private static function html(string $kind, array $attributes, string $address): string
    {
        $title = $attributes['title'] ?? '';
        switch ($kind) {
            case 'slides':
                return self::iframe($address, $title !== '' ? $title : 'Slides');
            case 'youtube':
                $id = self::youtubeId($address);
                return $id === '' ? '' : self::iframe('https://www.youtube-nocookie.com/embed/' . $id, $title !== '' ? $title : 'Video');
            case 'iframe':
                $url = $attributes['url'] ?? $attributes['src'] ?? '';
                return $url === '' ? '' : self::iframe($url, $title !== '' ? $title : 'Embedded content');
            case 'embedly':
                return self::embedlyCard($address);
            case 'pdf':
                return '[' . ($title !== '' ? $title : $address) . '](' . $address . ')';
            case 'linkpreview':
                $url = $attributes['url'] ?? '';
                return $url === '' ? '' : self::embedlyCard($url);
            case 'email':
                return '[' . $address . '](mailto:' . $address . ')';
            case 'h5p':
                // only url="…" works outside Grav ([h5p id="…"] needs the site's settings)
                $url = $attributes['url'] ?? '';
                if ($url === '') {
                    return '';
                }
                return self::h5p($url, $title !== '' ? $title : 'Interactive content');
            case 'button':
                $url = $attributes['url'] ?? '';
                return $url === '' ? '' : '[' . ($attributes['label'] ?? $url) . '](' . $url . ' \':class=button\')';
        }
        return '';
    }

    // An H5P activity, as in the Starter Kits: its iframe and H5P's resizer script
    private static function h5p(string $url, string $title): string
    {
        return '<iframe src="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($title) . '" width="100%" height="400" frameborder="0" allowfullscreen="allowfullscreen"></iframe>'
            . '<script src="https://h5p.org/sites/all/modules/h5p/library/js/h5p-resizer.js" charset="UTF-8"></script>';
    }

    private static function embedlyCard(string $url): string
    {
        return '<a class="embedly-card" data-card-theme="light" data-card-controls="0" data-card-align="left" href="'
            . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a>';
    }

    // An image cropped to a wide banner (at least twice as wide as it's tall) → the kits' banner image; other crops
    // stay as they are ('' = unchanged)
    private static function banner(string $alt, string $address): string
    {
        preg_match('/^(.*)\?cropZoom=([0-9]+),([0-9]+)$/', $address, $found);
        if ((int) $found[2] < 2 * (int) $found[3]) {
            return '';
        }
        // a crop this converter made from a kit's tall banner (1200,420) goes back to it
        $class = 'banner-image';
        if ($found[2] . ',' . $found[3] === Links::BANNER_CROPS['banner-tall-image']) {
            $class = 'banner-tall-image';
        }
        return '![' . $alt . '](' . $found[1] . " ':class=" . $class . "')";
    }

    // Grav's Font Awesome 4 names: a name ending in -o is the regular (outline) style in Font Awesome 6, others solid
    private static function icon(array $match): string
    {
        $name = $match[1];
        $style = 'fas';
        if (str_ends_with($name, '-o')) {
            $style = 'far';
            $name = substr($name, 0, -2);
        }
        return '<!-- ' . self::NOTE . ': ' . $match[0] . ' -->:' . $style . ' fa-' . $name . ' fa-fw:';
    }

    // A responsive iframe, as in the Docsify course template (Docsify-This and the kits style video-container-16by9)
    private static function iframe(string $url, string $title): string
    {
        return '<div class="video-container-16by9"><iframe src="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($title)
            . '" frameborder="0" allowfullscreen="true"></iframe></div>';
    }

    // https://www.youtube.com/watch?v=ID, https://youtu.be/ID or just the ID → ID
    private static function youtubeId(string $address): string
    {
        if (preg_match('/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/', $address, $found) === 1) {
            return $found[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $address) === 1) {
            return $address;
        }
        return '';
    }

    // ' title="Slides" url="…"' → ['title' => 'Slides', 'url' => '…']
    private static function attributes(string $text): array
    {
        preg_match_all('/([a-z][a-z0-9_-]*)="([^"]*)"/i', $text, $found, PREG_SET_ORDER);
        $attributes = [];
        foreach ($found as $match) {
            $attributes[strtolower($match[1])] = $match[2];
        }
        return $attributes;
    }

    // ---- Docsify → Grav ----

    // A hidden note and the line after it (the embed's HTML or link) → the original shortcode
    // A line of the author's text that Docsify shows in another way (such as a heading that repeats the H1), kept as
    // it was in a hidden note
    // $headingsMoved: the headings after it moved up a level (### → ##), so they move back down on the way back
    public static function hiddenLine(string $line, bool $headingsMoved = false): string
    {
        $note = $headingsMoved ? self::NOTE . '-line-headings-up' : self::NOTE . '-line';
        return '<!-- ' . $note . ': ' . str_replace('-->', '--&gt;', $line) . ' -->';
    }

    public static function toGrav(string $markdown): string
    {
        // a hidden line whose following headings moved up: they move back down (## → ###), outside code
        if (preg_match('/<!-- ' . self::NOTE . '-line-headings-up: /', $markdown) === 1) {
            $codeParts = [];
            $markdown = Helpers::hideCode($markdown, $codeParts);
            $markdown = preg_replace_callback('/(<!-- ' . self::NOTE . '-line-headings-up: .*? -->)(.*)$/s', function ($match) {
                return $match[1] . preg_replace('/^(#{2,5}\s)/m', '#$1', $match[2]);
            }, $markdown);
            $markdown = Helpers::showCode($markdown, $codeParts);
            $markdown = str_replace('<!-- ' . self::NOTE . '-line-headings-up: ', '<!-- ' . self::NOTE . '-line: ', $markdown);
        }
        $markdown = preg_replace_callback('/<!-- ' . self::NOTE . '-line: (.*?) -->/', function ($match) {
            return str_replace('--&gt;', '-->', $match[1]);
        }, $markdown);
        // icons first: their note and icon share a line
        $markdown = preg_replace('/<!-- ' . self::NOTE . ': (\[fa[^\n]*?) -->:fa[srb] fa-[a-z0-9-]+ fa-fw:/', '$1', $markdown);
        return preg_replace('/\n?(> )?<!-- ' . self::NOTE . ': (.*?) -->\n(?:> )?[^\n]*\n?(?(1)> )/', '$2', $markdown);
    }

    // Embedded pages written in Docsify as HTML (no hidden note) → Grav's embed shortcodes, as Grav 2 shows a raw
    // <iframe> as text: Google Slides → [googleslides], YouTube → [youtube], H5P → [h5p] (which resizes it, so H5P's
    // resizer script is left out), anything else → [iframe url="…" /] (16:9 inside the Starter Kits'
    // video-container-16by9, otherwise Open Matter's usual 4:3)
    public static function iframesToGrav(string $markdown): string
    {
        // an iframe, maybe in a <div>, maybe followed by H5P's resizer script
        $pattern = '/(?:<div class="([a-z0-9 -]*)">\s*)?<iframe\s([^>]*)>\s*<\/iframe>(?:\s*<\/div>)?(\s*<script [^>]*h5p-resizer[^>]*><\/script>)?/i';
        return preg_replace_callback($pattern, function ($match) {
            // the iframe's src and title
            if (preg_match('/\ssrc="([^"]+)"/i', ' ' . $match[2], $found) !== 1) {
                return $match[0];
            }
            $url = html_entity_decode($found[1], ENT_QUOTES);
            $title = '';
            if (preg_match('/\stitle="([^"]*)"/i', ' ' . $match[2], $found) === 1) {
                $title = ' title="' . str_replace('"', "'", html_entity_decode($found[1], ENT_QUOTES)) . '"';
            }
            if (str_contains($url, 'docs.google.com/presentation')) {
                return '[googleslides' . $title . ']' . $url . '[/googleslides]';
            }
            if (str_contains($url, '/h5p/embed/') || str_contains($url, 'h5p.com/content/')) {
                return '[h5p url="' . $url . '"' . $title . ' /]';
            }
            if (preg_match('/youtube(?:-nocookie)?\.com\/embed\/([A-Za-z0-9_-]+)/', $url, $found) === 1) {
                return '[youtube]https://www.youtube.com/watch?v=' . $found[1] . '[/youtube]';
            }
            $ratio = str_contains($match[1], '16by9') ? ' ratio="16:9"' : '';
            return '[iframe url="' . $url . '"' . $ratio . $title . ' /]';
        }, $markdown);
    }

    // Icons written in Docsify (no hidden note): :fas fa-calendar fa-pull-left: → [fa="calendar"][/fa], and the
    // regular style (far) → Font Awesome 4's -o name, as Grav uses: :far fa-calendar: → [fa="calendar-o"][/fa].
    // Options such as fa-fw are left out. Font Awesome icons shown as images from api.iconify.design (as in the
    // Starter Kits' sidebar links) become icons too: Grav can't show those images (it reads ?height=16 as its own
    // image option).
    public static function iconsToGrav(string $markdown): string
    {
        // Font Awesome 6 names → their Font Awesome 4 names, as Open Matter uses Font Awesome 4
        $renamed = [
            'circle-check' => 'check-circle', 'circle-info' => 'info-circle', 'circle-question' => 'question-circle',
            'triangle-exclamation' => 'exclamation-triangle', 'file-lines' => 'file-text', 'pen-to-square' => 'pencil-square',
            'magnifying-glass' => 'search', 'house' => 'home', 'gear' => 'cog', 'xmark' => 'times',
            'arrow-up-right-from-square' => 'external-link', 'up-right-from-square' => 'external-link', 'keyboard' => 'keyboard-o',
        ];
        $markdown = preg_replace_callback('/!\[[^\]]*\]\(https:\/\/api\.iconify\.design\/fa6-(solid|regular|brands)\/([a-z0-9-]+)\.svg[^)\s]*\)/', function ($match) use ($renamed) {
            $name = $match[2];
            if (isset($renamed[$name])) {
                $name = $renamed[$name];
            }
            if ($match[1] === 'regular' && !str_ends_with($name, '-o')) {
                $name .= '-o';
            }
            return '[fa="' . $name . '"][/fa] ';
        }, $markdown);
        return preg_replace_callback('/:(fa[srb]) fa-([a-z0-9-]+)(?: fa-[a-z0-9-]+)*:/', function ($match) use ($renamed) {
            $name = $match[2];
            if (isset($renamed[$name])) {
                $name = $renamed[$name];
            }
            if ($match[1] === 'far' && !str_ends_with($name, '-o')) {
                $name .= '-o';
            }
            return '[fa="' . $name . '"][/fa]';
        }, $markdown);
    }
}
