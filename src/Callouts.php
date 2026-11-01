<?php
namespace OPG;

// Open Publishing Space's callout shortcodes ([reflection] … [/reflection]) and Docsify's callouts (> [!TIP])
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

    // A callout with a bold heading, each line quoted: > [!TIP] / > **Reflection** / > text …
    public static function quote(string $type, string $heading, string $text): string
    {
        $lines = array_merge(['[!' . $type . ']', '**' . $heading . '**', ''], explode("\n", trim($text)));
        return implode("\n", array_map(fn($line) => $line === '' ? '>' : '> ' . $line, $lines));
    }

    public static function toDocsify(string $markdown): string
    {
        $names = implode('|', array_map('preg_quote', array_keys(self::SHORTCODES)));
        // [name attributes]text[/name]. Several shortcodes share one callout type, so a hidden note keeps
        // the shortcode (and its attributes) for the way back.
        $markdown = preg_replace_callback(
            '/\[(' . $names . ')((?: [^\]]*)?)\]\n?(.*?)\[\/\1\]/s',
            function ($m) {
                [$type, $heading] = self::SHORTCODES[$m[1]];
                if (preg_match('/type="([^"]*)"/', $m[2], $t)) {
                    $type = strtoupper($t[1]);
                }
                if (preg_match('/title="([^"]*)"/', $m[2], $t)) {
                    $heading = $t[1];
                }
                return '<!-- grav: [' . $m[1] . $m[2] . "] -->\n" . self::quote($type, $heading, $m[3]);
            },
            $markdown
        );
        // Content only for embedded pages (in an LMS) stays in Grav: kept hidden here, restored on the way back
        $markdown = preg_replace_callback(
            '/\[showifembedded\](.*?)\[\/showifembedded\]/s',
            fn($m) => "<!-- grav: [showifembedded]\n" . str_replace('-->', '--&gt;', $m[1]) . "\n[/showifembedded] -->",
            $markdown
        );
        $markdown = preg_replace('/\[(\/?)hideifembedded\]/', '<!-- grav: [$1hideifembedded] -->', $markdown);
        // layout-only shortcodes (such as [topics], which sets out an A–Z list) have nothing to do in Docsify:
        // their tags are kept hidden, for the way back
        $layout = implode('|', self::LAYOUT_SHORTCODES);
        $markdown = preg_replace('/\[(\/?)(' . $layout . ')\]/', '<!-- grav: [$1$2] -->', $markdown);
        return $markdown;
    }

    public const LAYOUT_SHORTCODES = ['topics'];

    // Other shortcodes Docsify can't show (an embed such as [googleslides], for example), for the warnings.
    // Code (in backticks or code blocks) and links ([text](url)) aren't shortcodes.
    public static function unknownShortcodes(string $markdown): array
    {
        $markdown = preg_replace('/```.*?```|~~~.*?~~~|`[^`\n]*`|<!--.*?-->/s', '', $markdown);
        preg_match_all('/\[\/?([a-z][a-z0-9-]*)(?:\s[^\]]*)?\](?![(\[:])/', $markdown, $m);
        return array_values(array_unique($m[1]));
    }

    // In Grav, Learning Objectives are their own box, so a TIP in the text is the page's only TIP. Here the
    // objectives are a TIP too, so the text's TIPs become NOTEs (no two callouts of one type on a page).
    // A TIP written as > [!TIP] gets a hidden note, so it can become a TIP again on the way back.
    public static function tipsToNotes(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^> \[!TIP\]/', $line)) {
                $previous = end($out);
                if ($previous === false || !str_starts_with((string)$previous, '<!-- grav: [')) {
                    $out[] = '<!-- grav: [!TIP] -->';
                }
                $line = preg_replace('/^> \[!TIP\]/', '> [!NOTE]', $line);
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    // Docsify → Grav. A callout with a hidden note goes back to its shortcode, exactly; a TIP that became a NOTE
    // becomes a TIP again; content kept hidden for embedded pages comes back. A callout without a note (from a
    // Docsify-first site) becomes a shortcode when its type and heading match one (> [!IMPORTANT] / **Example**),
    // otherwise it stays a > [!TYPE] callout, which the Open Publishing Space themes show too.
    public static function toGrav(string $markdown): string
    {
        $markdown = preg_replace_callback(
            '/<!-- grav: \[showifembedded\]\n(.*?)\n\[\/showifembedded\] -->/s',
            fn($m) => '[showifembedded]' . str_replace('--&gt;', '-->', $m[1]) . '[/showifembedded]',
            $markdown
        );
        $markdown = preg_replace('/<!-- grav: \[(\/?)hideifembedded\] -->/', '[$1hideifembedded]', $markdown);
        $markdown = preg_replace('/<!-- grav: \[(\/?)(' . implode('|', self::LAYOUT_SHORTCODES) . ')\] -->/', '[$1$2]', $markdown);
        $markdown = preg_replace('/<!-- grav: \[!TIP\] -->\n> \[!NOTE\]/', '> [!TIP]', $markdown);

        $lines = explode("\n", $markdown);
        $out = [];
        for ($i = 0; $i < count($lines); $i++) {
            $shortcode = null;
            if (preg_match('/^<!-- grav: \[([a-z-]+)((?: [^\]]*)?)\] -->$/', $lines[$i], $m) && isset(self::SHORTCODES[$m[1]])) {
                $shortcode = [$m[1], $m[2]];
                $i++;
            }
            if ($i < count($lines) && preg_match('/^> \[!([A-Z]+)\]\s*$/', $lines[$i], $t)) {
                // the callout's lines: > [!TYPE], > **Heading**, >, then its text
                $quoted = [];
                for ($j = $i + 1; $j < count($lines) && preg_match('/^>( |$)/', $lines[$j]); $j++) {
                    $quoted[] = preg_replace('/^> ?/', '', $lines[$j]);
                }
                $heading = null;
                if ($quoted && preg_match('/^\*\*(.+)\*\*$/', $quoted[0], $h)) {
                    $heading = $h[1];
                    array_shift($quoted);
                    if ($quoted && $quoted[0] === '') {
                        array_shift($quoted);
                    }
                }
                if ($shortcode === null && $heading !== null) {
                    foreach (self::SHORTCODES as $name => [$type, $default]) {
                        if ($type === $t[1] && $default === $heading) {
                            $shortcode = [$name, ''];
                            break;
                        }
                    }
                }
                if ($shortcode !== null) {
                    $out[] = '[' . $shortcode[0] . $shortcode[1] . ']';
                    $out = array_merge($out, $quoted);
                    $out[] = '[/' . $shortcode[0] . ']';
                    $i = $j - 1;
                    continue;
                }
            } elseif ($shortcode !== null) {
                // a note without its callout (the callout was removed in Docsify): drop the note
                $i--;
                continue;
            }
            $out[] = $lines[$i];
        }
        return implode("\n", $out);
    }
}
