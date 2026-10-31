<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Larsadmin;

/*
 * ADR-040, design 1.6 §7: the skills must mention the whole public surface, which is discovered on every
 * run (guide pages, larsadmin:* commands, config leaves, LARSADMIN_* variables, public classes, facade
 * methods, JS SDK / kit exports), and everything the skills reference must exist. Exemptions only in
 * tests/Ai/coverage-exemptions.php.
 *
 * Expected red until the skill writer bolts land; CI runs it once they do. Until then exclude it with
 * `vendor/bin/pest --exclude-group=skill-coverage`.
 */
uses()->group('skill-coverage');

function coveragePath(string $path = ''): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)).($path === '' ? '' : '/'.$path);
}

/**
 * Skill name => the text of its SKILL.md and references/*.md, with JSON-escaped backslashes folded.
 *
 * @return array<string, string>
 */
function coverageSkillTexts(): array
{
    $texts = [];

    foreach (glob(coveragePath('resources/boost/skills/*/SKILL.md')) as $skill) {
        $files = [$skill, ...glob(dirname($skill).'/references/*.md')];
        $texts[basename(dirname($skill))] = str_replace('\\\\', '\\', implode("\n", array_map('file_get_contents', $files)));
    }

    ksort($texts);

    return $texts;
}

/**
 * Package-relative path => text of every file a skill or the guideline consists of.
 *
 * @return array<string, string>
 */
function coverageAllAiFiles(): array
{
    $files = [
        ...glob(coveragePath('resources/boost/guidelines/*.md')),
        ...glob(coveragePath('resources/boost/skills/*/SKILL.md')),
        ...glob(coveragePath('resources/boost/skills/*/references/*.md')),
    ];

    $texts = [];

    foreach ($files as $file) {
        $texts[substr(str_replace('\\', '/', $file), strlen(coveragePath()) + 1)] = str_replace('\\\\', '\\', file_get_contents($file));
    }

    return $texts;
}

/** @return array<string, string> exemption => reason */
function coverageExemptions(): array
{
    return require __DIR__.'/coverage-exemptions.php';
}

/** @return array<string, list<string>> public class FQCN => stable members (kit/public-api.json) */
function coveragePublicApi(): array
{
    return json_decode(file_get_contents(coveragePath('kit/public-api.json')), true, flags: JSON_THROW_ON_ERROR)['php'];
}

/** @return array<string, mixed> config/larsadmin.php as shipped (not the test overrides) */
function coverageConfig(): array
{
    return require coveragePath('config/larsadmin.php');
}

/**
 * Leaves of config/larsadmin.php in dot notation. Lists (middleware, blocked extensions) and maps keyed by
 * user data (locales) count as one leaf.
 *
 * @return list<string>
 */
function coverageConfigLeaves(array $config, string $prefix = 'larsadmin'): array
{
    $userKeyed = ['larsadmin.locales'];
    $leaves = [];

    foreach ($config as $key => $value) {
        $path = $prefix.'.'.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value) && ! in_array($path, $userKeyed, true)) {
            array_push($leaves, ...coverageConfigLeaves($value, $path));
        } else {
            $leaves[] = $path;
        }
    }

    return $leaves;
}

/** @return list<string> LARSADMIN_* variables read with env() in config/ and src/ */
function coverageEnvVariables(): array
{
    $found = [];

    foreach (['config', 'src'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(coveragePath($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match_all("/env\\('(LARSADMIN_[A-Z0-9_]+)'/", file_get_contents($file->getPathname()), $m)) {
                array_push($found, ...$m[1]);
            }
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    return $found;
}

/** @return list<string> larsadmin:* command names */
function coverageCommands(): array
{
    $commands = array_values(array_filter(array_keys(Artisan::all()), fn (string $name) => str_starts_with($name, 'larsadmin:')));
    sort($commands);

    return $commands;
}

/** @return array<string, string> FQCN => package-relative file, for every class, trait, interface and enum in src/ */
function coverageSourceClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(coveragePath('src'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(coveragePath('src')) + 1);
        $classes['Maxcxam\\Larsadmin\\'.str_replace('/', '\\', substr($relative, 0, -4))] = 'src/'.$relative;
    }

    ksort($classes);

    return $classes;
}

/**
 * Whether the class / trait / interface / enum declared in a src/ file has @internal in its docblock, or null
 * when the file declares none. Read from the tokens, not by reflection: autoloading src/Mcp/** fails when
 * the optional laravel/mcp package is not installed.
 */
function coverageDeclaresInternal(string $file): ?bool
{
    $doc = null;
    $previous = null;
    $attribute = 0; // bracket depth inside #[...] between the docblock and the declaration

    foreach (token_get_all(file_get_contents($file)) as $token) {
        $id = is_array($token) ? $token[0] : null;

        if ($attribute > 0 || $id === T_ATTRIBUTE) {
            $attribute += match (true) {
                $id === T_ATTRIBUTE, $token === '[' => 1,
                $token === ']' => -1,
                default => 0,
            };

            continue;
        }

        if ($id === T_DOC_COMMENT) {
            $doc = $token[1];
        } elseif (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && $previous !== T_DOUBLE_COLON) {
            return preg_match('/@internal\b/', (string) $doc) === 1;
        } elseif (! in_array($id, [T_WHITESPACE, T_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
            $doc = null; // the docblock belongs to something else (namespace, use, ...)
        }

        if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $previous = $id;
        }
    }

    return null;
}

/** @return ?string the src/ file of a Maxcxam\Larsadmin class (PSR-4), or null */
function coverageClassFile(string $class): ?string
{
    $file = coveragePath('src/'.str_replace('\\', '/', substr($class, strlen('Maxcxam\\Larsadmin\\'))).'.php');

    return is_file($file) ? $file : null;
}

/** @return list<string> public static methods of the Larsadmin facade without @internal */
function coverageFacadeMethods(): array
{
    $methods = [];

    foreach ((new ReflectionClass(Larsadmin::class))->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isPublic() && $method->isStatic() && $method->class === Larsadmin::class
            && ! preg_match('/@internal\b/', (string) $method->getDocComment())) {
            $methods[] = $method->getName();
        }
    }

    sort($methods);

    return $methods;
}

/**
 * The skill expected to mention an element (design §3.2), for the failure message.
 */
function coverageSuggestedSkill(string $kind, string $element): string
{
    if ($kind === 'guide') {
        $page = (int) substr(basename($element), 0, 2);

        return match (true) {
            in_array($page, [1, 20], true) => 'larsadmin-setup',
            $page === 2 => 'larsadmin-entities',
            in_array($page, [3, 12], true) => 'larsadmin-fields-layout',
            $page === 4 => 'larsadmin-relations',
            $page === 5 => 'larsadmin-authorization-security',
            $page === 6 => 'larsadmin-filters',
            $page === 7 => 'larsadmin-actions',
            in_array($page, [8, 9, 11], true) => 'larsadmin-ui-i18n-theming',
            in_array($page, [10, 19], true) => 'larsadmin-extending',
            in_array($page, [13, 14], true) => 'larsadmin-metrics-dashboards',
            $page === 15 => 'larsadmin-files',
            $page === 16 => 'larsadmin-global-search',
            $page === 17 => 'larsadmin-audit-log',
            $page === 18 => 'larsadmin-notifications-realtime',
            $page === 21 => 'larsadmin-testing',
            default => 'the skill of that area',
        };
    }

    if ($kind === 'command') {
        return match (substr($element, strlen('larsadmin:'))) {
            'entity' => 'larsadmin-entities',
            'filter' => 'larsadmin-filters',
            'action' => 'larsadmin-actions',
            'dashboard', 'value', 'trend', 'partition', 'progress' => 'larsadmin-metrics-dashboards',
            'extension', 'field', 'card', 'tool' => 'larsadmin-extending',
            'prune-audit' => 'larsadmin-audit-log',
            'prune-notifications' => 'larsadmin-notifications-realtime',
            default => 'larsadmin-setup',
        };
    }

    if ($kind === 'config') {
        return 'larsadmin-setup (references/config.md)';
    }

    if ($kind === 'env') {
        return 'larsadmin-setup (references/env.md)';
    }

    if ($kind === 'facade') {
        return match ($element) {
            'auth' => 'larsadmin-setup and larsadmin-authorization-security',
            'timezoneUsing', 'metricCacheScopeUsing' => 'larsadmin-metrics-dashboards',
            'audited' => 'larsadmin-extending and larsadmin-audit-log',
            'auditActorLabelUsing' => 'larsadmin-audit-log',
            'extension', 'routes', 'findRecord', 'findField', 'registeredExtensions', 'extensionFor', 'registeredTools', 'toolForKey', 'assetsVersion' => 'larsadmin-extending (references/php-api.md)',
            'resolveLocaleUsing' => 'larsadmin-ui-i18n-theming',
            'flushState' => 'larsadmin-testing',
            default => 'larsadmin-setup, or mark the method @internal if it is not public API',
        };
    }

    if ($kind === 'js') {
        return 'larsadmin-extending (references/js-sdk.md)';
    }

    // Classes: a few by name, the rest by namespace.
    $relative = substr($element, strlen('Maxcxam\\Larsadmin\\'));
    $byClass = [
        'Fields\\BelongsTo' => 'larsadmin-relations', 'Fields\\BelongsToMany' => 'larsadmin-relations', 'Fields\\HasMany' => 'larsadmin-relations',
        'Events\\Attached' => 'larsadmin-relations', 'Events\\Detached' => 'larsadmin-relations',
        'Fields\\File' => 'larsadmin-files', 'Fields\\Image' => 'larsadmin-files',
        'Events\\AuditRecorded' => 'larsadmin-audit-log', 'Events\\ActionRunFinished' => 'larsadmin-actions',
        'Metrics\\DataCard' => 'larsadmin-extending', 'Metrics\\CardRequest' => 'larsadmin-extending',
        'Entity' => 'larsadmin-entities',
    ];
    $byNamespace = [
        'Fields' => 'larsadmin-fields-layout', 'Layout' => 'larsadmin-fields-layout', 'Filters' => 'larsadmin-filters',
        'Actions' => 'larsadmin-actions', 'Metrics' => 'larsadmin-metrics-dashboards', 'Dashboards' => 'larsadmin-metrics-dashboards',
        'Files' => 'larsadmin-files', 'Audit' => 'larsadmin-audit-log', 'Notifications' => 'larsadmin-notifications-realtime',
        'Extensions' => 'larsadmin-extending', 'Tools' => 'larsadmin-extending', 'Testing' => 'larsadmin-testing',
    ];

    return $byClass[$relative] ?? $byNamespace[explode('\\', $relative)[0]] ?? 'larsadmin-setup';
}

/**
 * The skills where the stable members of a public class must be mentioned: the area of the class, plus
 * larsadmin-extending (references/php-api.md documents the extension hooks).
 *
 * @return list<string>
 */
function coverageMemberAreas(string $class): array
{
    $areas = [explode(' ', coverageSuggestedSkill('class', $class))[0], 'larsadmin-extending'];

    if ($class === Field::class) {
        $areas[] = 'larsadmin-actions'; // inAction() / actionValue() (design §8.3)
    }

    return array_values(array_unique($areas));
}

/** @return list<string> public members the panel keeps internal (INTERNAL_PHP_MEMBERS in kit/check-public-api.mjs) */
function coverageInternalMembers(): array
{
    preg_match('/const INTERNAL_PHP_MEMBERS = \[(.*?)\]/s', file_get_contents(coveragePath('kit/check-public-api.mjs')), $list);
    preg_match_all("/'([A-Za-z_]\\w*)'/", preg_replace('~//[^\n]*~', '', $list[1] ?? ''), $names);

    return $names[1];
}

/**
 * Every element of the public surface of one kind: id (as printed and as used in the exemptions) =>
 * [mention pattern, skill(s) the mention must be in or null for any, suggested skill].
 *
 * @return array<string, array{0: string, 1: string|list<string>|null, 2: string}>
 */
function coverageSurface(string $kind): array
{
    $surface = [];
    $add = function (string $element, string $pattern, string|array|null $only = null) use (&$surface, $kind) {
        $surface[$kind.' '.$element] = [$pattern, $only, coverageSuggestedSkill($kind, $element)];
    };

    switch ($kind) {
        case 'guide':
            foreach (glob(coveragePath('docs/guide/en/*.md')) as $page) {
                $path = 'docs/guide/en/'.basename($page);
                $add($path, '/'.preg_quote($path, '/').'/');
            }
            break;

        case 'command':
            foreach (coverageCommands() as $command) {
                $add($command, '/(?<![\w\/-])'.preg_quote($command, '/').'(?![\w-])/');
            }
            break;

        case 'config':
            foreach (coverageConfigLeaves(coverageConfig()) as $key) {
                $add($key, '/(?<![\w\/.-])'.preg_quote($key, '/').'(?![\w-])/');
            }
            break;

        case 'env':
            foreach (coverageEnvVariables() as $variable) {
                $add($variable, '/\b'.$variable.'\b/');
            }
            break;

        case 'class':
            foreach (array_keys(coveragePublicApi()) as $class) {
                $add($class, '/(?<![\w\\\\])'.preg_quote($class, '/').'(?![\w\\\\])/');
            }
            break;

        case 'member':
            // Stable members of the public classes: Class::member, ->member( or member( in the area of the class.
            foreach (coveragePublicApi() as $class => $members) {
                $areas = coverageMemberAreas($class);

                foreach ($members as $member) {
                    $name = preg_quote(ltrim($member, '$'), '/');
                    $pattern = match (true) {
                        str_starts_with($member, '$') => '/\$'.$name.'\b/',
                        preg_match('/^[A-Z][A-Z0-9_]*$/', $member) === 1 => '/(?<![\w$])'.$name.'\b/',
                        default => '/(?:::'.$name.'\b|(?<![\w$])'.$name.'\s*\()/',
                    };
                    $surface["member {$class}::{$member}"] = [$pattern, $areas, implode(' or ', $areas)];
                }
            }
            break;

        case 'facade':
            foreach (coverageFacadeMethods() as $method) {
                $add($method, '/(?<![\w\\\\])Larsadmin::'.$method.'(?!\w)/');
            }
            break;

        case 'js':
            $js = json_decode(file_get_contents(coveragePath('kit/public-api.json')), true, flags: JSON_THROW_ON_ERROR)['js'];

            foreach ($js['larsadmin'] as $export) {
                $add($export, '/`'.preg_quote($export, '/').'(?![\w$])[^`\n]*`/', 'larsadmin-extending');
            }

            foreach ($js['kit'] as $file => $exports) {
                $add('kit/'.$file, '/(?<![\w-])'.preg_quote($file, '/').'(?!\w)/', 'larsadmin-extending');

                foreach ($exports as $export) {
                    if ($export !== 'default') {
                        $add($export.' ('.$file.')', '/`'.preg_quote($export, '/').'(?![\w$])[^`\n]*`/', 'larsadmin-extending');
                    }
                }
            }
            break;
    }

    return $surface;
}

/**
 * @return list<string> the kinds of surface elements
 */
function coverageKinds(): array
{
    return ['guide', 'command', 'config', 'env', 'class', 'member', 'facade', 'js'];
}

it('ships the same guide pages in both languages', function () {
    $uk = array_map('basename', glob(coveragePath('docs/guide/*.md')));
    $en = array_map('basename', glob(coveragePath('docs/guide/en/*.md')));

    expect(array_values(array_diff($uk, $en)))->toBe([], 'Guide pages without an English version (docs/guide/en/).')
        ->and(array_values(array_diff($en, $uk)))->toBe([], 'English guide pages without a docs/guide/ version.');
});

it('mentions every :dataset of the public surface in a skill', function (string $kind) {
    $texts = coverageSkillTexts();
    $exemptions = coverageExemptions();
    $missing = [];

    expect($texts)->not->toBeEmpty('No skills found in resources/boost/skills/.');

    foreach (coverageSurface($kind) as $id => [$pattern, $only, $skill]) {
        if (array_key_exists($id, $exemptions)) {
            continue;
        }

        $searched = $only === null ? $texts : array_intersect_key($texts, array_flip((array) $only));

        if (! array_filter($searched, fn (string $text) => preg_match($pattern, $text) === 1)) {
            $missing[] = "  - {$id}: mention it in {$skill}";
        }
    }

    $hint = [
        'guide' => 'the literal path docs/guide/en/NN-name.md (e.g. vendor/maxcxam/larsadmin/docs/guide/en/07-actions.md)',
        'command' => 'the command name, e.g. larsadmin:entity',
        'config' => 'the full key in dot notation, e.g. larsadmin.search.limit',
        'env' => 'the variable name',
        'class' => 'the fully qualified class name, e.g. in a `use` line of an example',
        'member' => 'Class::member, ->member( or member( (properties: $name) in one of the suggested skills',
        'facade' => 'Larsadmin::method',
        'js' => 'the export name in backticks in larsadmin-extending (kit files: the file name)',
    ][$kind];

    expect($missing)->toBe([], sprintf(
        "%d %s element(s) are not mentioned in any SKILL.md or references/*.md. A mention is %s.\n"
        ."Add the mention to the suggested skill, or (with a reason) to tests/Ai/coverage-exemptions.php:\n%s",
        count($missing), $kind, $hint, implode("\n", $missing),
    ));
})->with([
    'guide page' => ['guide'],
    'artisan command' => ['command'],
    'config key' => ['config'],
    'environment variable' => ['env'],
    'public class' => ['class'],
    'stable class member' => ['member'],
    'facade method' => ['facade'],
    'JS SDK and kit export' => ['js'],
]);

it('marks every class in src/ as public (kit/public-api.json) or @internal', function () {
    $public = coveragePublicApi();
    $exemptions = coverageExemptions();
    $violations = [];

    foreach (coverageSourceClasses() as $class => $file) {
        if (array_key_exists('invariant '.$class, $exemptions)) {
            continue;
        }

        $internal = coverageDeclaresInternal(coveragePath($file));

        if ($internal === null) {
            $violations[] = "  - invariant {$class}: {$file} declares no class, trait, interface or enum";
        } elseif ($internal && isset($public[$class])) {
            $violations[] = "  - invariant {$class}: {$file} is in kit/public-api.json but marked @internal; pick one";
        } elseif (! $internal && ! isset($public[$class])) {
            $violations[] = "  - invariant {$class}: {$file} is neither in kit/public-api.json nor marked @internal";
        }
    }

    expect($violations)->toBe([], sprintf(
        "%d class(es) in src/ break the ADR-040 invariant. Add `@internal` to the class docblock, or make the class public\n"
        ."(PHP_API in kit/check-public-api.mjs, `npm run check:api -- --update`) and mention it in a skill:\n%s",
        count($violations), implode("\n", $violations),
    ));
});

it('only references surface that exists from the skills and the guideline', function () {
    $commands = coverageCommands();
    $env = coverageEnvVariables();
    $config = coverageConfig();
    $guides = array_map('basename', glob(coveragePath('docs/guide/en/*.md')));
    $stale = [];

    foreach (coverageAllAiFiles() as $file => $text) {
        preg_match_all('/Maxcxam\\\\Larsadmin(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $text, $m);
        foreach (array_unique($m[0]) as $class) {
            // From the source files, so src/Mcp/** is checked without autoloading it (laravel/mcp is optional).
            $source = coverageClassFile($class);
            $exists = $source !== null
                || is_dir(coveragePath('src/'.str_replace('\\', '/', substr($class, strlen('Maxcxam\\Larsadmin\\')))));

            if (! $exists) {
                $stale[] = "  - {$file}: class or namespace {$class} does not exist";
            } elseif ($source !== null && coverageDeclaresInternal($source)) {
                $stale[] = "  - {$file}: {$class} is @internal, not public API";
            }
        }

        foreach (coverageInternalMembers() as $member) {
            if (preg_match('/(?:->|::|(?<![\w$]))'.$member.'\s*\(/', $text)) {
                $stale[] = "  - {$file}: {$member}() is internal (INTERNAL_PHP_MEMBERS in kit/check-public-api.mjs), not public API";
            }
        }

        preg_match_all('/(?<![\w\/-])larsadmin:([a-z][a-z0-9-]*)/', $text, $m);
        foreach (array_unique($m[0]) as $command) {
            if (! in_array($command, $commands, true)) {
                $stale[] = "  - {$file}: command {$command} does not exist";
            }
        }

        preg_match_all('/\bLARSADMIN_[A-Z0-9][A-Z0-9_]*\b/', $text, $m);
        foreach (array_unique($m[0]) as $variable) {
            $known = str_ends_with($variable, '_')
                ? array_filter($env, fn (string $name) => str_starts_with($name, $variable)) !== []
                : in_array($variable, $env, true);

            if (! $known) {
                $stale[] = "  - {$file}: environment variable {$variable} is not read by config/ or src/";
            }
        }

        // Config keys: inside config(...) calls, or a whole `larsadmin.top_level.key` code span (route names excluded).
        preg_match_all('/config\(\s*\[?\s*[\'"]larsadmin\.([A-Za-z0-9_.]+)[\'"]/', $text, $calls);
        preg_match_all('/`larsadmin\.([a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*)`/', $text, $spans);
        $keys = [
            ...$calls[1],
            ...array_filter($spans[1], fn (string $key) => array_key_exists(explode('.', $key)[0], $config) && ! Route::has('larsadmin.'.$key)),
        ];
        foreach (array_unique($keys) as $key) {
            if (! Arr::has($config, $key)) {
                $stale[] = "  - {$file}: config key larsadmin.{$key} does not exist in config/larsadmin.php";
            }
        }

        preg_match_all('/(?<![\w\\\\])Larsadmin::([A-Za-z_][A-Za-z0-9_]*)/', $text, $m);
        foreach (array_unique($m[1]) as $member) {
            $exists = $member === 'class' || defined(Larsadmin::class.'::'.$member)
                || (method_exists(Larsadmin::class, $member) && (new ReflectionMethod(Larsadmin::class, $member))->isPublic());

            if (! $exists) {
                $stale[] = "  - {$file}: Larsadmin::{$member} does not exist";
            } elseif (method_exists(Larsadmin::class, $member) && preg_match('/@internal\b/', (string) (new ReflectionMethod(Larsadmin::class, $member))->getDocComment())) {
                $stale[] = "  - {$file}: Larsadmin::{$member} is @internal, not public API";
            }
        }

        preg_match_all('/docs\/guide\/(?:en\/)?(\d{2}-[a-z0-9-]+\.md)/', $text, $m);
        foreach (array_unique($m[1]) as $page) {
            if (! in_array($page, $guides, true)) {
                $stale[] = "  - {$file}: guide page {$page} does not exist in docs/guide/en/";
            }
        }
    }

    expect($stale)->toBe([], sprintf(
        "%d stale reference(s) in the skills or the guideline (renamed or removed API, R-23). Fix or remove them:\n%s",
        count($stale), implode("\n", $stale),
    ));
});

it('keeps every coverage exemption justified and current', function () {
    $current = array_keys(array_merge(...array_map('coverageSurface', coverageKinds())));
    $classes = array_map(fn (string $class) => 'invariant '.$class, array_keys(coverageSourceClasses()));
    $problems = [];

    foreach (coverageExemptions() as $element => $reason) {
        if (! is_string($reason) || trim($reason) === '') {
            $problems[] = "  - {$element}: no reason given";
        }

        if (! in_array($element, [...$current, ...$classes], true)) {
            $problems[] = "  - {$element}: not an element of the current surface (remove the exemption)";
        }
    }

    expect($problems)->toBe([], "tests/Ai/coverage-exemptions.php:\n".implode("\n", $problems));
});
