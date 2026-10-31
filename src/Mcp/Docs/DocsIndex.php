<?php

namespace Maxcxam\Larsadmin\Mcp\Docs;

use InvalidArgumentException;

/**
 * The full-text index of the English guides shipped with the installed package (docs/guide/en/*.md, Q-77) for the
 * MCP tools search-docs and read-docs. Pages are discovered by listing the directory, so a new guide is picked up
 * without code changes; a page is only ever read through that listing (no paths from the client). Sections are the
 * "##" and "###" headings, ranked with BM25 (k1 = 1.2, b = 0.75), the section title counted twice and an exact
 * phrase match adding 1; English stop words are dropped and plain plurals folded. Built lazily once per process, no disk cache, no external dependencies.
 *
 * @internal
 */
final class DocsIndex
{
    public const float K1 = 1.2;

    public const float B = 0.75;

    /**
     * The maximum length of a search excerpt, in characters.
     */
    public const int EXCERPT_LENGTH = 600;

    /**
     * The file name of a guide page: "NN-name.md".
     */
    private const string PAGE_PATTERN = '/^(\d{2}-[a-z0-9]+(?:-[a-z0-9]+)*)\.md$/';

    /**
     * A token: letters, digits and "_", joined by ":", ".", "-" inside identifiers (larsadmin:entity, config.php).
     */
    private const string TOKEN_PATTERN = '/[\p{L}\p{N}_]+(?:[:.\-][\p{L}\p{N}_]+)*/u';

    private const array STOP_WORDS = [
        'a', 'about', 'after', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'before',
        'but', 'by', 'can', 'do', 'does', 'each', 'for', 'from', 'has', 'have', 'how', 'if', 'in', 'into', 'is',
        'it', 'its', 'just', 'may', 'more', 'most', 'no', 'not', 'of', 'on', 'only', 'or', 'other', 'our', 'out',
        'own', 'same', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these',
        'they', 'this', 'those', 'through', 'to', 'too', 'under', 'up', 'very', 'was', 'we', 'were', 'what',
        'when', 'where', 'which', 'while', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
    ];

    /**
     * @var array<string, self>
     */
    private static array $shared = [];

    /**
     * @var array<string, string>|null page slug => absolute file path
     */
    private ?array $files = null;

    /**
     * @var array<string, array{slug: string, title: string, lines: list<string>, headings: list<array{level: int, title: string, anchor: string, line: int}>}>
     */
    private array $parsed = [];

    /**
     * @var list<array{page: string, title: string, section: string, anchor: string, body: string, haystack: string, tf: array<string, float>, length: float, order: int}>|null
     */
    private ?array $units = null;

    /**
     * @var array<string, int> term => number of units containing it
     */
    private array $documentFrequency = [];

    private float $averageLength = 0.0;

    public function __construct(private readonly string $directory) {}

    /**
     * The English guides of the installed package copy.
     */
    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'guide'.DIRECTORY_SEPARATOR.'en';
    }

    /**
     * The index shared by the tools of this process (the stdio server lives as long as the client session).
     */
    public static function shared(?string $directory = null): self
    {
        $directory ??= self::defaultDirectory();

        return self::$shared[$directory] ??= new self($directory);
    }

    /**
     * The index the tools use: an instance bound in the container (tests), otherwise the shared default index.
     */
    public static function resolve(): self
    {
        $app = app();

        return $app->bound(self::class) ? $app->make(self::class) : self::shared();
    }

    /**
     * Forget the shared indexes (tests).
     */
    public static function flush(): void
    {
        self::$shared = [];
    }

    /**
     * The page slugs ("03-fields"), sorted: the allowlist of read-docs and of the page filter of search-docs.
     *
     * @return list<string>
     */
    public function pages(): array
    {
        return array_keys($this->files());
    }

    public function hasPage(string $page): bool
    {
        return array_key_exists($page, $this->files());
    }

    /**
     * Search the sections; the best matches first.
     *
     * @return list<array{page: string, title: string, section: string, anchor: string, score: float, excerpt: string}>
     */
    public function search(string $query, int $limit = 5, ?string $page = null): array
    {
        if ($page !== null && ! $this->hasPage($page)) {
            throw new InvalidArgumentException('Unknown page.');
        }

        $terms = array_values(array_unique(self::tokenize($query)));

        if ($terms === []) {
            return [];
        }

        $units = $this->units();
        $count = count($units);
        $phrase = self::collapse(mb_strtolower(trim($query)));
        $scored = [];

        foreach ($units as $unit) {
            if ($page !== null && $unit['page'] !== $page) {
                continue;
            }

            $score = 0.0;

            foreach ($terms as $term) {
                $tf = $unit['tf'][$term] ?? 0.0;

                if ($tf <= 0) {
                    continue;
                }

                $df = $this->documentFrequency[$term] ?? 0;
                $idf = log(1 + ($count - $df + 0.5) / ($df + 0.5));
                $norm = self::K1 * (1 - self::B + self::B * $unit['length'] / max($this->averageLength, 1.0));
                $score += $idf * ($tf * (self::K1 + 1)) / ($tf + $norm);
            }

            if ($score <= 0) {
                continue;
            }

            if ($phrase !== '' && str_contains($unit['haystack'], $phrase)) {
                $score += 1.0;
            }

            $scored[] = ['score' => $score, 'unit' => $unit];
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['unit']['order']] <=> [$a['score'], $b['unit']['order']]);

        return array_map(fn (array $hit) => [
            'page' => $hit['unit']['page'],
            'title' => $hit['unit']['title'],
            'section' => $hit['unit']['section'],
            'anchor' => $hit['unit']['anchor'],
            'score' => round($hit['score'], 3),
            'excerpt' => self::excerpt($hit['unit']['body'], $query, $terms),
        ], array_slice($scored, 0, max(1, $limit)));
    }

    /**
     * A page: its title, the table of contents ("##" and "###" headings) and the Markdown of one section with its
     * subsections; without a section the page lead and the first "##" section. Null for an unknown page or section.
     *
     * @return array{page: string, title: string, sections: list<array{title: string, anchor: string, level: int}>, content: string}|null
     */
    public function read(string $page, ?string $section = null): ?array
    {
        $parsed = $this->parse($page);

        if ($parsed === null) {
            return null;
        }

        $lines = $parsed['lines'];
        $headings = $parsed['headings'];
        $toc = array_values(array_filter($headings, fn (array $heading) => in_array($heading['level'], [2, 3], true)));

        if ($section === null) {
            $first = $toc[0] ?? null;
            $start = 0;
            $end = $first === null ? count($lines) : self::sectionEnd($headings, $first, count($lines));
        } else {
            $match = $this->findSection($toc, $section);

            if ($match === null) {
                return null;
            }

            $start = $match['line'];
            $end = self::sectionEnd($headings, $match, count($lines));
        }

        return [
            'page' => $parsed['slug'],
            'title' => $parsed['title'],
            'sections' => array_map(fn (array $heading) => [
                'title' => $heading['title'],
                'anchor' => $heading['anchor'],
                'level' => $heading['level'],
            ], $toc),
            'content' => trim(implode("\n", array_slice($lines, $start, $end - $start))),
        ];
    }

    /**
     * The lower-case search terms of a text: every identifier whole (larsadmin:entity, larsadmin_audit, indexquery)
     * and split into its parts (camelCase, "_", ":", "-", "."), without English stop words and one-letter parts.
     *
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        if (! preg_match_all(self::TOKEN_PATTERN, $text, $matches)) {
            return [];
        }

        $tokens = [];

        foreach ($matches[0] as $raw) {
            $whole = mb_strtolower($raw);

            if (! self::isStopWord($whole)) {
                $tokens[] = self::singular($whole);
            }

            $parts = preg_split('/[:.\-_]+|(?<=[\p{Ll}\p{N}])(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($parts) < 2) {
                continue;
            }

            foreach ($parts as $part) {
                $part = mb_strtolower($part);

                if (mb_strlen($part) >= 2 && ! self::isStopWord($part)) {
                    $tokens[] = self::singular($part);
                }
            }
        }

        return $tokens;
    }

    /**
     * The GitHub anchor of a heading: lower case, punctuation removed, each space a hyphen.
     */
    public static function slug(string $heading): string
    {
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $heading) ?? $heading;
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]/u', '', $text) ?? $text;

        return str_replace(' ', '-', $text);
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = [];
        $root = realpath($this->directory);

        foreach ($root === false ? [] : (scandir($root) ?: []) as $name) {
            if (! preg_match(self::PAGE_PATTERN, $name, $match)) {
                continue;
            }

            $path = realpath($root.DIRECTORY_SEPARATOR.$name);

            // Only regular files that really live in the guide directory (no symlink pointing elsewhere).
            if ($path !== false && is_file($path) && dirname($path) === $root) {
                $files[$match[1]] = $path;
            }
        }

        ksort($files, SORT_STRING);

        return $this->files = $files;
    }

    /**
     * @return array{slug: string, title: string, lines: list<string>, headings: list<array{level: int, title: string, anchor: string, line: int}>}|null
     */
    private function parse(string $page): ?array
    {
        if (isset($this->parsed[$page])) {
            return $this->parsed[$page];
        }

        $path = $this->files()[$page] ?? null;

        if ($path === null) {
            return null;
        }

        $source = (string) file_get_contents($path);
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $source));
        $headings = [];
        $anchors = [];
        $fence = null;

        foreach ($lines as $number => $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $marker)) {
                if ($fence === null) {
                    $fence = $marker[1][0];
                } elseif ($marker[1][0] === $fence) {
                    $fence = null;
                }

                continue;
            }

            if ($fence !== null || ! preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $match)) {
                continue;
            }

            $title = trim($match[2]);
            $anchor = self::slug($title);
            $anchors[$anchor] = ($anchors[$anchor] ?? -1) + 1;

            $headings[] = [
                'level' => strlen($match[1]),
                'title' => $title,
                'anchor' => $anchors[$anchor] === 0 ? $anchor : $anchor.'-'.$anchors[$anchor],
                'line' => $number,
            ];
        }

        $h1 = array_values(array_filter($headings, fn (array $heading) => $heading['level'] === 1))[0] ?? null;

        return $this->parsed[$page] = [
            'slug' => $page,
            'title' => $h1['title'] ?? $page,
            'lines' => $lines,
            'headings' => $headings,
        ];
    }

    /**
     * The search units: the lead of every page and each "##" / "###" section with its own text (up to the next
     * heading of level 1-3), so a parent section and its subsection do not compete with the same words.
     *
     * @return list<array{page: string, title: string, section: string, anchor: string, body: string, haystack: string, tf: array<string, float>, length: float, order: int}>
     */
    private function units(): array
    {
        if ($this->units !== null) {
            return $this->units;
        }

        $units = [];
        $documentFrequency = [];
        $totalLength = 0.0;

        foreach ($this->pages() as $page) {
            $parsed = $this->parse($page);

            if ($parsed === null) {
                continue;
            }

            $lines = $parsed['lines'];
            $boundaries = array_values(array_filter($parsed['headings'], fn (array $heading) => $heading['level'] <= 3));
            $lead = array_values(array_filter($boundaries, fn (array $heading) => $heading['level'] === 1))[0] ?? null;
            $pageTokens = self::tokenize($parsed['title']);
            $starts = [];

            if (($boundaries[0]['level'] ?? 1) > 1 || $lead === null) {
                $starts[] = ['level' => 1, 'title' => $parsed['title'], 'anchor' => $lead['anchor'] ?? '', 'line' => -1];
            }

            foreach ($boundaries as $heading) {
                $starts[] = $heading;
            }

            foreach ($starts as $index => $heading) {
                $from = $heading['line'] + 1;
                $to = $starts[$index + 1]['line'] ?? count($lines);
                $body = trim(implode("\n", array_slice($lines, $from, max(0, $to - $from))));
                $title = $heading['title'];

                if ($body === '' && $heading['level'] > 1) {
                    continue;
                }

                $tf = [];

                foreach (self::tokenize($body) as $token) {
                    $tf[$token] = ($tf[$token] ?? 0) + 1;
                }

                foreach (self::tokenize($title) as $token) {
                    $tf[$token] = ($tf[$token] ?? 0) + 2;
                }

                foreach ($pageTokens as $token) {
                    $tf[$token] = ($tf[$token] ?? 0) + 1;
                }

                foreach (array_keys($tf) as $term) {
                    $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
                }

                $length = (float) array_sum($tf);
                $totalLength += $length;

                $units[] = [
                    'page' => $page,
                    'title' => $parsed['title'],
                    'section' => $title,
                    'anchor' => $heading['anchor'],
                    'body' => $body,
                    'haystack' => self::collapse(mb_strtolower($title."\n".$body)),
                    'tf' => $tf,
                    'length' => $length,
                    'order' => count($units),
                ];
            }
        }

        $this->documentFrequency = $documentFrequency;
        $this->averageLength = $units === [] ? 0.0 : $totalLength / count($units);

        return $this->units = $units;
    }

    /**
     * @param  list<array{level: int, title: string, anchor: string, line: int}>  $toc
     * @return array{level: int, title: string, anchor: string, line: int}|null
     */
    private function findSection(array $toc, string $section): ?array
    {
        $wanted = ltrim(trim($section), '#');
        $wanted = trim($wanted);

        foreach ($toc as $heading) {
            if ($heading['anchor'] === $wanted) {
                return $heading;
            }
        }

        foreach ($toc as $heading) {
            if (mb_strtolower($heading['title']) === mb_strtolower($wanted) || $heading['anchor'] === self::slug($wanted)) {
                return $heading;
            }
        }

        return null;
    }

    /**
     * The line after a section: the next heading of the same or a higher level.
     *
     * @param  list<array{level: int, title: string, anchor: string, line: int}>  $headings
     * @param  array{level: int, title: string, anchor: string, line: int}  $section
     */
    private static function sectionEnd(array $headings, array $section, int $lineCount): int
    {
        foreach ($headings as $heading) {
            if ($heading['line'] > $section['line'] && $heading['level'] <= $section['level']) {
                return $heading['line'];
            }
        }

        return $lineCount;
    }

    /**
     * Up to EXCERPT_LENGTH characters of the body around the first match of the query.
     *
     * @param  list<string>  $terms
     */
    private static function excerpt(string $body, string $query, array $terms): string
    {
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body);
        $length = mb_strlen($text);

        if ($length <= self::EXCERPT_LENGTH) {
            return $text;
        }

        $position = null;

        foreach ([trim($query), ...$terms] as $needle) {
            if ($needle === '') {
                continue;
            }

            $found = mb_stripos($text, $needle);

            if ($found !== false) {
                $position = $found;

                break;
            }
        }

        $start = max(0, ($position ?? 0) - 150);

        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < ($position ?? $start) ? $space + 1 : $start;
        }

        $prefix = $start > 0 ? '…' : '';
        $room = self::EXCERPT_LENGTH - mb_strlen($prefix);
        $cut = $start + $room < $length;
        $slice = mb_substr($text, $start, $cut ? $room - 1 : $room);

        return $prefix.$slice.($cut ? '…' : '');
    }

    /**
     * A minimal plural folding of plain English words ("fields" → "field", "entities" → "entity"), so a query in
     * the singular finds the plural; identifiers with ":", "_", "-", "." and short words stay as they are.
     */
    private static function singular(string $token): string
    {
        if (strlen($token) < 4 || ! preg_match('/^[a-z]+$/', $token)) {
            return $token;
        }

        if (str_ends_with($token, 'ies') && strlen($token) > 4) {
            return substr($token, 0, -3).'y';
        }

        if (str_ends_with($token, 's') && ! preg_match('/(ss|us|is)$/', $token)) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    private static function isStopWord(string $token): bool
    {
        static $lookup = null;
        $lookup ??= array_flip(self::STOP_WORDS);

        return isset($lookup[$token]);
    }

    private static function collapse(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
