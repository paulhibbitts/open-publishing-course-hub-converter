<?php
namespace OPG;

class Helpers
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    // Grav orders pages with a number in front of the folder name ('01.what-is-open-education')
    public static function isNumbered(string $folderName): bool
    {
        return (bool)preg_match('/^\d+\./', $folderName);
    }

    public static function slugFromFolder(string $folderName): string
    {
        return preg_replace('/^\d+\./', '', $folderName);
    }

    public static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    public static function isImage(string $fileName): bool
    {
        return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    // Grav's last_updated ('04-10-2026 00:00', day first) as shown on the cards ('Oct 4, 2026')
    public static function shortDate(string $value): string
    {
        $day = strtok(trim($value), ' ');
        $date = \DateTime::createFromFormat('d-m-Y', (string)$day);
        return $date ? $date->format('M j, Y') : $value;
    }

    // The hidden note that lets a Docsify page go back to Grav unchanged. Docsify doesn't show HTML comments.
    // First line: where the page came from (and its images); then its front matter exactly as written.
    public static function gravNote(Page $page): string
    {
        $where = ['file' => ltrim($page->folder . '/' . $page->file, '/')];
        if ($page->images) {
            $where['images'] = $page->images;
        }
        $yaml = rtrim(str_replace('-->', '--&gt;', $page->yaml));
        return '<!-- grav: ' . json_encode($where, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . $yaml . "\n-->";
    }

    // Marks text the converter adds for Docsify (titles, cards, back links …), so it can be told apart from
    // the author's own text on the way back
    public static function generated(array $lines): array
    {
        return array_merge(['<!-- grav:gen -->'], $lines, ['<!-- /grav:gen -->']);
    }
}
