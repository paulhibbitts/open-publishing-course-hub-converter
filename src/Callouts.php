<?php
namespace OPG;

// Open Publishing Space's callout shortcodes ([reflection] … [/reflection]) and Docsify's callouts (> [!TIP]),
// plus the other shortcodes that need care when moving between the two
class Callouts
{
    // shortcode => [callout type, default heading], as in the themes' CourseContentShortcode.php
    public const SHORTCODES = [
        'objectives'         => ['TIP', 'Learning Objectives'],
        'key-takeaways'      => ['NOTE', 'Key Takeaways'],
        'reflection'         => ['TIP', 'Reflection'],
        'definition'         => ['NOTE', 'Definition'],
        'example'            => ['IMPORTANT', 'Example'],
        'case-study'         => ['CAUTION', 'Case Study'],
        'project-brief'      => ['WARNING', 'Project Brief'],
        'process-note'       => ['NOTE', 'Process Note'],
        'feedback-requested' => ['IMPORTANT', 'Feedback Requested'],
        'exercise'           => ['TIP', 'Exercise'],
        'announcement'       => ['IMPORTANT', 'Announcement'],
    ];

    // Shortcodes that only set out the page in Grav (such as [topics], an A–Z list, or [references], a list of
    // sources). Docsify doesn't need them, so their tags are kept hidden for the way back.
    public const LAYOUT_SHORTCODES = ['topics', 'references'];

    // An A–Z letter bar, as on the Docsify Starter Kits' Topics pages: [A](#a)|B|[C](#c)|… (a letter with topics links
    // to its heading). Grav's [topics] shortcode makes its own.
    private const LETTER_BAR = '(?:\[[A-Z]\]\(#[a-z]\)|[A-Z])(?:\|(?:\[[A-Z]\]\(#[a-z]\)|[A-Z])){25}';

    // A callout with a bold heading: > [!TIP] / > **Reflection** / > / > text …
    public static function quote(string $type, string $heading, string $text): string
    {
        $lines = '[!' . $type . "]\n**" . $heading . "**\n\n" . trim($text);
        return Helpers::quoteLines($lines);
    }

    // ---- Grav → Docsify ----

    public static function toDocsify(string $markdown): string
    {
        // [name attributes]text[/name] → a callout. Several shortcodes share one callout type, so a hidden note
        // keeps the shortcode (and its attributes) for the way back.
        $names = implode('|', array_keys(self::SHORTCODES));
        $markdown = preg_replace_callback(
            '/\[(' . $names . ')((?: [^\]]*)?)\]\n?(.*?)\[\/\1\]/s',
            [self::class, 'shortcodeToCallout'],
            $markdown
        );

        // Content only for embedded pages (in an LMS) stays in Grav: it's kept inside a hidden note
        $markdown = preg_replace_callback(
            '/\[showifembedded\](.*?)\[\/showifembedded\]/s',
            [self::class, 'hideEmbeddedContent'],
            $markdown
        );

        // [details summary="…"]text[/details] (a section readers open, as in Pressbooks books) → HTML's own
        // <details>, which Docsify shows as it is; the hidden note keeps the shortcode for the way back
        $markdown = preg_replace_callback('/\[details((?: [^\]]*)?)\]/', [self::class, 'detailsToHtml'], $markdown);
        $markdown = str_replace('[/details]', "\n</details><!-- grav: [/details] -->", $markdown);

        // a Topics page's A–Z list gets the Starter Kits' letter bar, as Grav shows one above it
        $markdown = preg_replace_callback('/\[topics\]\n?(.*?)\[\/topics\]/s', [self::class, 'addLetterBar'], $markdown);

        // [hideifembedded] and the layout shortcodes only wrap text: their tags become hidden notes
        $markdown = preg_replace('/\[(\/?)hideifembedded\]/', '<!-- grav: [$1hideifembedded] -->', $markdown);
        $layoutNames = implode('|', self::LAYOUT_SHORTCODES);
        $markdown = preg_replace('/\[(\/?)(' . $layoutNames . ')\]/', '<!-- grav: [$1$2] -->', $markdown);
        return $markdown;
    }

    // $match: [whole [topics] shortcode, its text]. The bar links each letter that has a ## heading.
    private static function addLetterBar(array $match): string
    {
        preg_match_all('/^## ([A-Z])$/m', $match[1], $found);
        $bar = [];
        foreach (range('A', 'Z') as $letter) {
            if (in_array($letter, $found[1], true)) {
                $bar[] = '[' . $letter . '](#' . strtolower($letter) . ')';
            } else {
                $bar[] = $letter;
            }
        }
        return "[topics]\n" . implode('|', $bar) . "\n\n" . $match[1] . '[/topics]';
    }

    // A letter bar written by addLetterBar (just after the hidden [topics] note) is dropped again, as Grav makes its
    // own. On a page the converter didn't make, a letter bar starts Grav's [topics] list, which runs to the end of the
    // page, so Grav shows its own bar there.
    public static function letterBarToTopics(string $markdown): string
    {
        $markdown = preg_replace('/(<!-- grav: \[topics\] -->)\n' . self::LETTER_BAR . '\n\n/', "$1\n", $markdown);
        if (str_contains($markdown, '[topics]') || str_contains($markdown, '<!-- grav: [topics] -->')) {
            return $markdown;
        }
        $withTopics = preg_replace('/^' . self::LETTER_BAR . '\n(?:\n)?/m', "[topics]\n", $markdown, 1, $count);
        if ($count === 0) {
            return $markdown;
        }
        return rtrim($withTopics) . "\n\n[/topics]";
    }

    // $match: [whole shortcode, its name, its attributes, its text]
    private static function shortcodeToCallout(array $match): string
    {
        $name = $match[1];
        $attributes = $match[2];
        $type = self::SHORTCODES[$name][0];
        $heading = self::SHORTCODES[$name][1];
        // type="" and title="" attributes change the callout's type and heading
        if (preg_match('/type="([^"]*)"/', $attributes, $found) === 1) {
            $type = strtoupper($found[1]);
        }
        if (preg_match('/title="([^"]*)"/', $attributes, $found) === 1) {
            $heading = $found[1];
        }
        // blank lines at the start or end of the text are kept, so the way back gives the same Grav page;
        // only the line break just before [/name] goes
        $text = $match[3];
        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }
        $lines = '[!' . $type . "]\n**" . $heading . "**\n\n" . $text;
        return '<!-- grav: [' . $name . $attributes . "] -->\n" . Helpers::quoteLines($lines);
    }

    // [details summary="Text version"] → <details><summary>Text version</summary>, followed by a blank line so
    // Docsify reads the text inside as Markdown
    private static function detailsToHtml(array $match): string
    {
        $summary = 'Details';
        if (preg_match('/summary="([^"]*)"/', $match[1], $found) === 1) {
            $summary = $found[1];
        }
        return '<!-- grav: [details' . $match[1] . '] --><details><summary>' . htmlspecialchars($summary) . "</summary>\n";
    }

    // Docsify shows a hidden note as text when a line inside it is blank or starts with > (a callout), so every line
    // of the content starts with | inside the note, and loses it again on the way back
    private const NOTE_LINE = '|';

    private static function hideEmbeddedContent(array $match): string
    {
        $content = str_replace('-->', '--&gt;', $match[1]);
        $lines = explode("\n", $content);
        foreach ($lines as $index => $line) {
            $lines[$index] = self::NOTE_LINE . $line;
        }
        $content = implode("\n", $lines);
        return "<!-- grav: [showifembedded]\n" . $content . "\n[/showifembedded] -->";
    }

    // In Grav, Learning Objectives are their own box, so a TIP in the text is the page's only TIP. In Docsify the
    // objectives are a TIP too, so the text's TIPs become NOTEs (no two callouts of one type on a page).
    // A TIP written as > [!TIP] gets a hidden note, so it can become a TIP again on the way back.
    public static function tipsToNotes(string $markdown): string
    {
        $result = [];
        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '> [!TIP]')) {
                $previousLine = '';
                if (count($result) > 0) {
                    $previousLine = $result[count($result) - 1];
                }
                // a TIP from a shortcode already has its note
                if (!str_starts_with($previousLine, '<!-- grav: [')) {
                    $result[] = '<!-- grav: [!TIP] -->';
                }
                $line = '> [!NOTE]' . substr($line, strlen('> [!TIP]'));
            }
            $result[] = $line;
        }
        return implode("\n", $result);
    }

    // Other shortcodes Docsify can't show (an embed such as [googleslides], for example), for the warnings.
    // Code (in backticks or code blocks), hidden notes and links ([text](url)) aren't shortcodes.
    public static function unknownShortcodes(string $markdown): array
    {
        $markdown = preg_replace('/```.*?```|~~~.*?~~~|`[^`\n]*`|<!--.*?-->/s', '', $markdown);
        preg_match_all('/\[\/?([a-z][a-z0-9-]*)(?:\s[^\]]*)?\](?![(\[:])/', $markdown, $found);
        return array_values(array_unique($found[1]));
    }

    // ---- Docsify → Grav ----

    // A callout with a hidden note goes back to its exact shortcode, and a TIP that became a NOTE becomes a TIP
    // again. A callout without a note (from a site made in Docsify) becomes a shortcode when its type and heading
    // match one (> [!IMPORTANT] with **Example** → [example]); otherwise it stays a > [!TYPE] callout, which the
    // Open Publishing Space themes also show.
    public static function toGrav(string $markdown): string
    {
        $markdown = preg_replace_callback(
            '/<!-- grav: \[showifembedded\]\n(.*?)\n\[\/showifembedded\] -->/s',
            [self::class, 'showEmbeddedContent'],
            $markdown
        );
        $markdown = preg_replace('/<!-- grav: \[(\/?)hideifembedded\] -->/', '[$1hideifembedded]', $markdown);
        // <details> made from [details] (see toDocsify) goes back to the shortcode; the summary is in the note
        $markdown = preg_replace('/<!-- grav: \[details((?: [^\]]*)?)\] --><details><summary>[^<]*<\/summary>\n/', '[details$1]', $markdown);
        $markdown = str_replace("\n</details><!-- grav: [/details] -->", '[/details]', $markdown);
        $markdown = self::letterBarToTopics($markdown);
        $layoutNames = implode('|', self::LAYOUT_SHORTCODES);
        $markdown = preg_replace('/<!-- grav: \[(\/?)(' . $layoutNames . ')\] -->/', '[$1$2]', $markdown);
        $markdown = str_replace("<!-- grav: [!TIP] -->\n> [!NOTE]", '> [!TIP]', $markdown);

        $lines = explode("\n", $markdown);
        $result = [];
        $i = 0;
        while ($i < count($lines)) {
            $shortcode = self::shortcodeInNote($lines[$i]);
            if ($shortcode !== null) {
                // the note itself isn't kept; the callout after it is read next
                $i++;
            }
            $calloutType = '';
            if ($i < count($lines)) {
                $calloutType = self::calloutType($lines[$i]);
            }
            if ($calloutType === '') {
                if ($shortcode !== null) {
                    // a note whose callout was removed in Docsify: the note is dropped, and line $i is read
                    // again as an ordinary line
                    continue;
                }
                $result[] = $lines[$i];
                $i++;
                continue;
            }

            // collect the callout's quoted lines, without their '> '
            $quoted = [];
            $next = $i + 1;
            while ($next < count($lines) && preg_match('/^>( |$)/', $lines[$next]) === 1) {
                $quoted[] = preg_replace('/^> ?/', '', $lines[$next]);
                $next++;
            }
            $heading = self::takeHeading($quoted);

            if ($shortcode === null && $heading !== '') {
                $shortcode = self::shortcodeFor($calloutType, $heading);
            }
            if ($shortcode === null) {
                // no matching shortcode: the callout stays as it is
                $result[] = $lines[$i];
                $i++;
                continue;
            }
            $result[] = '[' . $shortcode . ']';
            foreach ($quoted as $line) {
                $result[] = $line;
            }
            $result[] = '[/' . self::shortcodeName($shortcode) . ']';
            $i = $next;
        }
        return implode("\n", $result);
    }

    private static function showEmbeddedContent(array $match): string
    {
        // (a note written before the | lines were added is read as it is)
        $lines = explode("\n", $match[1]);
        $allMarked = true;
        foreach ($lines as $line) {
            if (!str_starts_with($line, self::NOTE_LINE)) {
                $allMarked = false;
            }
        }
        if ($allMarked) {
            foreach ($lines as $index => $line) {
                $lines[$index] = substr($line, strlen(self::NOTE_LINE));
            }
        }
        $content = implode("\n", $lines);
        return '[showifembedded]' . str_replace('--&gt;', '-->', $content) . '[/showifembedded]';
    }

    // '<!-- grav: [reflection title="Think"] -->' → 'reflection title="Think"' (or null for any other line)
    private static function shortcodeInNote(string $line): ?string
    {
        if (preg_match('/^<!-- grav: \[([a-z-]+)((?: [^\]]*)?)\] -->$/', $line, $found) !== 1) {
            return null;
        }
        if (!isset(self::SHORTCODES[$found[1]])) {
            return null;
        }
        return $found[1] . $found[2];
    }

    // 'reflection title="Think"' → 'reflection'
    private static function shortcodeName(string $shortcode): string
    {
        $parts = explode(' ', $shortcode, 2);
        return $parts[0];
    }

    // '> [!TIP]' → 'TIP' (or '' when the line doesn't start a callout)
    private static function calloutType(string $line): string
    {
        if (preg_match('/^> \[!([A-Z]+)\]\s*$/', $line, $found) !== 1) {
            return '';
        }
        return $found[1];
    }

    // A callout's first line is its bold heading (and then an empty line): both are taken out of $quoted
    private static function takeHeading(array &$quoted): string
    {
        if (count($quoted) === 0 || preg_match('/^\*\*(.+)\*\*$/', $quoted[0], $found) !== 1) {
            return '';
        }
        array_shift($quoted);
        if (count($quoted) > 0 && $quoted[0] === '') {
            array_shift($quoted);
        }
        return $found[1];
    }

    // The shortcode whose callout type and default heading match, or null
    private static function shortcodeFor(string $type, string $heading): ?string
    {
        foreach (self::SHORTCODES as $name => $calloutStyle) {
            if ($calloutStyle[0] === $type && $calloutStyle[1] === $heading) {
                return $name;
            }
        }
        return null;
    }
}
