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
        return $markdown;
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
}
