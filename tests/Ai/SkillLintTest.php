<?php

use Maxcxam\Larsadmin\Larsadmin;
use Symfony\Component\Yaml\Yaml;

/*
 * ADR-036 / ADR-040, design 1.6 §3 and §7: the shape of the AI guideline and the 15 skills. The frontmatter
 * is parsed with symfony/yaml, the parser Laravel Boost uses.
 */

function lintPath(string $path = ''): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)).($path === '' ? '' : '/'.$path);
}

/** @return list<string> the skills of the FR-064 map (design §3.2), directory = name */
function lintSkillNames(): array
{
    return [
        'larsadmin-setup',
        'larsadmin-entities',
        'larsadmin-fields-layout',
        'larsadmin-relations',
        'larsadmin-authorization-security',
        'larsadmin-filters',
        'larsadmin-actions',
        'larsadmin-metrics-dashboards',
        'larsadmin-files',
        'larsadmin-global-search',
        'larsadmin-audit-log',
        'larsadmin-notifications-realtime',
        'larsadmin-ui-i18n-theming',
        'larsadmin-extending',
        'larsadmin-testing',
    ];
}

/** @return list<string> the mandatory SKILL.md sections, in order (design §3.3) */
function lintSections(): array
{
    return ['When to use', 'Quick start', 'Recipes', 'API cheat sheet', 'Security rules', 'Pitfalls', 'Reference'];
}

/**
 * Pattern => what it catches. Misspellings of the package name and the names of other admin panels (F-52).
 *
 * @return array<string, string>
 */
function lintForbiddenWords(): array
{
    return [
        '/\bLarsAdmin\b/' => 'misspelled package name (write "Larsadmin")',
        '/\bLARSADMIN\b/' => 'misspelled package name (write "Larsadmin")',
        '/\blars[ _-]admin\b/i' => 'misspelled package name (write "Larsadmin")',
        '/\b(?:larsadmine|larsadmn|larsamdin|larasadmin|laraadmin|lrsadmin)\b/i' => 'misspelled package name (write "Larsadmin")',
        '/\blaravel-admin\b/i' => 'name of another admin package',
        '/\b(?:nova|filament|backpack|orchid|voyager|moonshine|twill)\b/i' => 'name of another admin panel',
    ];
}

/**
 * Lines outside fenced code blocks (line number => text) and the fenced blocks themselves.
 *
 * @return array{0: array<int, string>, 1: list<array{lang: string, line: int, code: string}>}
 */
function lintMarkdown(string $text): array
{
    $prose = [];
    $blocks = [];
    $fence = null;

    foreach (preg_split('/\R/', $text) as $index => $line) {
        $number = $index + 1;

        if ($fence === null && preg_match('/^(\s*)(`{3,}|~{3,})\s*([\w+-]*)/', $line, $m)) {
            $fence = ['indent' => strlen($m[1]), 'marker' => $m[2], 'lang' => strtolower($m[3]), 'line' => $number, 'lines' => []];
        } elseif ($fence !== null && preg_match('/^\s*'.preg_quote($fence['marker'], '/').'\s*$/', $line)) {
            $blocks[] = [
                'lang' => $fence['lang'],
                'line' => $fence['line'],
                'code' => implode("\n", array_map(fn (string $l) => substr($l, min($fence['indent'], strspn($l, ' '))), $fence['lines'])),
            ];
            $fence = null;
        } elseif ($fence !== null) {
            $fence['lines'][] = $line;
        } else {
            $prose[$number] = $line;
        }
    }

    return [$prose, $blocks];
}

/** @return array{0: array<string, mixed>, 1: string} frontmatter and body of a SKILL.md */
function lintFrontmatter(string $text): array
{
    if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $text, $m)) {
        throw new RuntimeException('SKILL.md must start with a YAML frontmatter block between --- lines.');
    }

    $data = Yaml::parse($m[1]);

    if (! is_array($data)) {
        throw new RuntimeException('The frontmatter is not a YAML mapping.');
    }

    return [$data, $m[2]];
}

/** @return Generator<string, array{0: string}> package-relative path of the guideline and every skill file */
function lintAiFiles(): Generator
{
    $files = [
        ...glob(lintPath('resources/boost/guidelines/*.md')),
        ...glob(lintPath('resources/boost/skills/*/SKILL.md')),
        ...glob(lintPath('resources/boost/skills/*/references/*.md')),
    ];

    foreach ($files as $file) {
        $relative = substr(str_replace('\\', '/', $file), strlen(lintPath()) + 1);

        yield $relative => [$relative];
    }
}

it('ships exactly the skills of the FR-064 map, each with a SKILL.md', function () {
    $directories = array_map('basename', glob(lintPath('resources/boost/skills/*'), GLOB_ONLYDIR));
    sort($directories);
    $expected = lintSkillNames();
    sort($expected);

    expect($directories)->toBe($expected);

    foreach ($expected as $skill) {
        expect(lintPath("resources/boost/skills/{$skill}/SKILL.md"))->toBeFile();
    }
});

it('has valid frontmatter and the section template', function (string $skill) {
    $file = "resources/boost/skills/{$skill}/SKILL.md";
    $text = file_get_contents(lintPath($file));
    [$meta, $body] = lintFrontmatter($text);

    expect($meta['name'] ?? null)->toBe($skill, "{$file}: frontmatter name must equal the directory name")
        ->and($skill)->toMatch('/^[a-z0-9-]{1,64}$/')
        ->and($meta['description'] ?? null)->toBeString("{$file}: frontmatter description is required");

    $description = $meta['description'];

    expect(trim($description))->not->toBe('', "{$file}: description is empty")
        ->and(mb_strlen($description))->toBeLessThanOrEqual(1024, "{$file}: description is longer than 1024 characters")
        ->and($description)->toMatch('/\bUse when\b/', "{$file}: description must read \"What it covers. Use when ...\"")
        ->and($meta['license'] ?? null)->toBe('MIT', "{$file}: license must be MIT")
        ->and($meta['metadata']['package'] ?? null)->toBe('maxcxam/larsadmin', "{$file}: metadata.package")
        ->and($meta['metadata']['version'] ?? null)->toBe(Larsadmin::API_VERSION, "{$file}: metadata.version must be the quoted package minor version");

    [$prose] = lintMarkdown($body);
    $headings = array_values(array_filter($prose, fn (string $line) => preg_match('/^#{1,2} /', $line) === 1));

    expect($headings[0] ?? null)->toStartWith('# ', "{$file}: the body must start with a # title");

    $sections = array_map(fn (string $line) => trim(substr($line, 3)), array_filter($headings, fn (string $line) => str_starts_with($line, '## ')));
    $template = array_values(array_filter($sections, fn (string $section) => in_array($section, lintSections(), true)));

    expect($template)->toBe(lintSections(), "{$file}: the ## sections ".implode(', ', lintSections()).' must each appear once, in this order')
        ->and(count(preg_split('/\R/', rtrim($text))))->toBeLessThanOrEqual(400, "{$file}: SKILL.md is longer than 400 lines; move long tables to references/*.md");
})->with(array_combine(lintSkillNames(), array_map(fn (string $skill) => [$skill], lintSkillNames())));

it('keeps the guideline short and routes to every skill', function () {
    $file = 'resources/boost/guidelines/core.md';
    $text = file_get_contents(lintPath($file));

    expect(count(preg_split('/\R/', rtrim($text))))->toBeLessThanOrEqual(80, "{$file} is longer than 80 lines");

    $unlisted = array_values(array_filter(lintSkillNames(), fn (string $skill) => preg_match('/(?<![\w-])'.preg_quote($skill, '/').'(?![\w-])/', $text) !== 1));

    expect($unlisted)->toBe([], "{$file} must route to every skill; missing: ".implode(', ', $unlisted));
});

it('is written in English without forbidden words', function (string $file) {
    $problems = [];

    foreach (preg_split('/\R/', file_get_contents(lintPath($file))) as $index => $line) {
        if (preg_match('/\p{Cyrillic}/u', $line)) {
            $problems[] = sprintf('  - line %d: Cyrillic text (skills are English only)', $index + 1);
        }

        foreach (lintForbiddenWords() as $pattern => $reason) {
            if (preg_match($pattern, $line, $m)) {
                $problems[] = sprintf('  - line %d: "%s", %s', $index + 1, $m[0], $reason);
            }
        }
    }

    expect($problems)->toBe([], "{$file}:\n".implode("\n", $problems));
})->with(fn () => lintAiFiles());

it('has relative links that resolve', function (string $file) {
    [$prose] = lintMarkdown(file_get_contents(lintPath($file)));
    $broken = [];

    foreach ($prose as $number => $line) {
        preg_match_all('/\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $line, $m);

        foreach ($m[1] as $target) {
            if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|#)/i', $target)) {
                continue; // URL, mailto: or an anchor in the same file
            }

            $path = rawurldecode(explode('#', $target)[0]);

            if (str_starts_with($path, '/')) {
                $broken[] = "  - line {$number}: {$target} is absolute; use a path relative to the file";

                continue;
            }

            // The versioned guide is linked as vendor/maxcxam/larsadmin/... (design §3.1); check it in this package.
            $resolved = str_starts_with($path, 'vendor/maxcxam/larsadmin/')
                ? lintPath(substr($path, strlen('vendor/maxcxam/larsadmin/')))
                : dirname(lintPath($file)).'/'.$path;

            if (! file_exists($resolved)) {
                $broken[] = "  - line {$number}: {$target} does not exist";
            }
        }
    }

    expect($broken)->toBe([], "{$file}:\n".implode("\n", $broken));
})->with(fn () => lintAiFiles());

it('has PHP code blocks that parse', function (string $file) {
    [, $blocks] = lintMarkdown(file_get_contents(lintPath($file)));
    $errors = [];

    foreach ($blocks as $block) {
        if ($block['lang'] !== 'php') {
            continue;
        }

        $prefixed = ! str_starts_with(ltrim($block['code']), '<?php');
        $code = $prefixed ? "<?php\n".$block['code'] : $block['code'];

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            $errors[] = sprintf('  - block at line %d: %s (line %d of the block)', $block['line'], $e->getMessage(), $e->getLine() - ($prefixed ? 1 : 0));
        }
    }

    expect($errors)->toBe([], "{$file}: PHP examples must be valid PHP (statements, not fragments):\n".implode("\n", $errors));
})->with(fn () => lintAiFiles());
