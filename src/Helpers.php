<?php
namespace OPG;

class Helpers
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    // Grav orders pages with a number in front of the folder name ('01.what-is-open-education')
    public static function isNumbered(string $folderName): bool
    {
        return preg_match('/^\d+\./', $folderName) === 1;
    }

    public static function slugFromFolder(string $folderName): string
    {
        return preg_replace('/^\d+\./', '', $folderName);
    }

    // 'Open Education Essentials' → 'open-education-essentials' (for a folder name)
    public static function slugify(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false && $ascii !== '') {
            $text = $ascii;
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if ($text === '') {
            return 'guide';
        }
        return $text;
    }

    public static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    public static function isImage(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    // An image file's full path, only when it really is inside $siteFolder (a link such as ../../etc/hosts in an
    // uploaded site mustn't copy a file from elsewhere on the server); otherwise ''
    public static function imageInside(string $siteFolder, string $path): string
    {
        if (!self::isImage($path)) {
            return '';
        }
        $realSite = realpath($siteFolder);
        $realPath = realpath($path);
        if ($realSite === false || $realPath === false || !is_file($realPath)) {
            return '';
        }
        if (!str_starts_with($realPath, $realSite . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return $realPath;
    }

    // Grav's last_updated ('04-10-2026 00:00', day first) as shown on the cards ('Oct 4, 2026')
    public static function shortDate(string $value): string
    {
        $dayPart = strtok(trim($value), ' ');
        $date = \DateTime::createFromFormat('d-m-Y', (string)$dayPart);
        if ($date === false) {
            return $value;
        }
        return $date->format('M j, Y');
    }

    // The hidden note that lets a Docsify page go back to Grav unchanged (Docsify doesn't show HTML comments).
    // First line: where the page came from (and its images); then its front matter exactly as written.
    public static function gravNote(Page $page, array $extra = []): string
    {
        $where = ['file' => ltrim($page->folder . '/' . $page->file, '/')];
        if (count($page->images) > 0) {
            $where['images'] = $page->images;
        }
        foreach ($extra as $name => $value) {
            $where[$name] = $value;
        }
        $firstLine = json_encode($where, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // a comment ends at -->, so any --> in the front matter is written as --&gt;
        $yaml = rtrim(str_replace('-->', '--&gt;', $page->yaml));
        return '<!-- grav: ' . $firstLine . "\n" . $yaml . "\n-->";
    }

    // Reads a hidden note back. Returns null when the text doesn't start with one, otherwise
    // ['where' => what the first line holds, 'yaml' => the front matter, 'rest' => the rest of the page]
    public static function readGravNote(string $text): ?array
    {
        $found = preg_match('/^<!-- grav: (\{.*?\})\n(.*?)\n?-->\n?(.*)$/s', ltrim($text), $match);
        if ($found !== 1) {
            return null;
        }
        $where = json_decode($match[1], true);
        if ($where === null) {
            return null;
        }
        return [
            'where' => $where,
            'yaml' => str_replace('--&gt;', '-->', $match[2]),
            'rest' => $match[3],
        ];
    }

    // Marks text the converter adds for Docsify (titles, cards, back links …), so it can be told apart from
    // the author's own text on the way back
    public static function generated(array $lines): array
    {
        $marked = ['<!-- grav:gen -->'];
        foreach ($lines as $line) {
            $marked[] = $line;
        }
        $marked[] = '<!-- /grav:gen -->';
        return $marked;
    }

    // Quotes each line for a Markdown callout or blockquote: 'text' → '> text', and an empty line → '>'
    public static function quoteLines(string $text): string
    {
        $quoted = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                $quoted[] = '>';
            } else {
                $quoted[] = '> ' . $line;
            }
        }
        return implode("\n", $quoted);
    }

    // Code is shown as it is, so shortcodes and links inside it (such as `[example]…[/example]`) must not be
    // converted: code blocks and `code` are swapped for placeholders, and put back by showCode() afterwards
    public static function hideCode(string $markdown, array &$codeParts): string
    {
        // a fenced block (``` or ~~~ to the same fence), or `code` on one line
        $pattern = '/^(```|~~~).*?^\1|`[^`\n]+`/ms';
        return preg_replace_callback($pattern, function ($match) use (&$codeParts) {
            // one placeholder per line, so a code block inside a callout still gets '> ' on every line
            $placeholders = [];
            foreach (explode("\n", $match[0]) as $line) {
                if ($line === '') {
                    $placeholders[] = '';
                    continue;
                }
                $codeParts[] = $line;
                $placeholders[] = self::CODE_MARK . (count($codeParts) - 1) . self::CODE_MARK;
            }
            return implode("\n", $placeholders);
        }, $markdown);
    }

    public static function showCode(string $markdown, array $codeParts): string
    {
        return preg_replace_callback('/' . self::CODE_MARK . '(\d+)' . self::CODE_MARK . '/u', function ($match) use ($codeParts) {
            return $codeParts[(int)$match[1]];
        }, $markdown);
    }

    // a character that never appears in written text (Unicode private use), to mark the placeholders
    private const CODE_MARK = "\u{E000}";
}
