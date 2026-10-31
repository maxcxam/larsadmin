<?php

namespace Maxcxam\Larsadmin\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Mcp\McpGate;
use RuntimeException;
use SplFileInfo;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\multiselect;

/**
 * Install the larsadmin AI tooling without Laravel Boost (ADR-036, ADR-037, Q-73): copy the skills into the agent's
 * skills directory, add the MCP server to .mcp.json and import the guidelines into CLAUDE.md. Every target stays
 * inside the application root (no links), nothing written by someone else is overwritten without --force.
 *
 * @internal
 */
#[AsCommand(name: 'larsadmin:ai')]
class AiCommand extends Command
{
    /**
     * The marker file in every skill directory this command created: the package version and the content hash.
     */
    public const string MARKER = '.larsadmin-skill';

    /**
     * The guidelines import line for CLAUDE.md.
     */
    public const string CLAUDE_IMPORT = '@vendor/maxcxam/larsadmin/resources/boost/guidelines/core.md';

    /**
     * Test switch: the directory the skills are copied from; null is the package's resources/boost/skills.
     */
    public static ?string $skillsSource = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larsadmin:ai
        {--skills : Copy the larsadmin skills into the skills directory (--path)}
        {--mcp : Add the larsadmin MCP server to .mcp.json}
        {--claude-md : Import the larsadmin guidelines into CLAUDE.md}
        {--path=.claude/skills : The skills directory, relative to the application root}
        {--force : Overwrite skills changed by hand or not created by this command, and the "larsadmin" .mcp.json entry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Larsadmin skills, MCP server entry and guidelines for AI assistants (without Laravel Boost)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $steps = array_values(array_filter(['skills', 'mcp', 'claude-md'], fn (string $step) => (bool) $this->option($step)));

        if ($steps === []) {
            $steps = $this->input->isInteractive()
                ? array_values(array_map('strval', multiselect(
                    label: 'What should be installed for your AI assistant?',
                    options: [
                        'skills' => 'Skills (.claude/skills/larsadmin-*)',
                        'mcp' => 'MCP server entry (.mcp.json)',
                        'claude-md' => 'Guidelines import in CLAUDE.md',
                    ],
                    default: ['skills', 'mcp'],
                )))
                : ['skills', 'mcp'];
        }

        $failed = false;

        try {
            if (in_array('skills', $steps, true)) {
                $failed = ! $this->installSkills() || $failed;
            }

            if (in_array('mcp', $steps, true)) {
                $failed = ! $this->installMcp() || $failed;
            }

            if (in_array('claude-md', $steps, true)) {
                $failed = ! $this->installClaudeMd() || $failed;
            } elseif ($this->claudeMdGuidelines() === null) {
                $this->components->info('Tip: add the line "'.self::CLAUDE_IMPORT.'" to CLAUDE.md (or run with --claude-md) to load the larsadmin guidelines.');
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy every larsadmin-* skill directory. Directories without our marker or changed since they were written
     * are left alone unless --force.
     */
    protected function installSkills(): bool
    {
        $relative = ExtensionScaffold::relativeDirectory((string) $this->option('path'), 'path');
        $target = base_path($relative);
        $source = self::$skillsSource ?? __DIR__.'/../../resources/boost/skills';
        $skills = is_dir($source) ? $this->skillDirectories($source) : [];

        if ($skills === []) {
            $this->components->error('No larsadmin skills found in the package.');

            return false;
        }

        $version = McpGate::packageVersion();
        $force = (bool) $this->option('force');
        $boostSkills = $this->boostSkills();

        // Every target is checked before anything is written: a link anywhere on the way must not lead out.
        foreach (array_keys($skills) as $name) {
            if (! $this->safeTarget($target.'/'.$name)) {
                throw new InvalidArgumentException("The skill directory [{$relative}/{$name}] leads outside the application root or through a link.");
            }
        }

        foreach ($skills as $name => $directory) {
            $destination = $target.'/'.$name;
            $display = $relative.'/'.$name;

            if (file_exists($destination)) {
                if (! is_dir($destination)) {
                    $this->components->warn("Skipped {$display}: it is not a directory.");

                    continue;
                }

                $marker = $this->readMarker($destination);

                if (! $force && $marker === null) {
                    $this->components->warn(in_array($name, $boostSkills, true)
                        ? "Skipped {$display}: it was installed by Laravel Boost. Update it with `php artisan boost:update`, or pass --force to take it over."
                        : "Skipped {$display}: it was not created by larsadmin:ai (use --force to replace it).");

                    continue;
                }

                if (! $force && ! hash_equals($marker['hash'], $this->hashDirectory($destination))) {
                    $this->components->warn("Skipped {$display}: it was changed by hand (use --force to replace it).");

                    continue;
                }

                $this->deleteDirectory($destination);
            }

            $files = $this->copyDirectory($directory, $destination);
            file_put_contents($destination.'/'.self::MARKER, json_encode([
                'package' => 'maxcxam/larsadmin',
                'version' => $version,
                'hash' => $this->hashDirectory($destination),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            $this->components->info("Installed {$display} ({$files} files).");
        }

        return true;
    }

    /**
     * Whether a path is a link: a symlink, or anything that is neither a plain file nor a plain directory (a Windows
     * junction is reported as type "unknown", not as a link).
     */
    protected function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        $type = @filetype($path);

        return $type !== false && $type !== 'dir' && $type !== 'file';
    }

    /**
     * Whether a path stays inside the application root with no link (junctions included) on the way to it.
     */
    protected function safeTarget(string $path): bool
    {
        if (! ExtensionScaffold::insideBase($path)) {
            return false;
        }

        $root = rtrim(base_path(), '/\\');

        for ($segment = $path; strlen($segment) > strlen($root) && dirname($segment) !== $segment; $segment = dirname($segment)) {
            if ($this->isLink($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The larsadmin-* skill directories of the source (name => path), sorted by name.
     *
     * @return array<string, string>
     */
    protected function skillDirectories(string $source): array
    {
        $skills = [];

        foreach (new FilesystemIterator($source) as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isDir() && ! $this->isLink($entry->getPathname()) && preg_match('/^larsadmin-[a-z0-9-]{1,54}\z/', $entry->getFilename()) === 1) {
                $skills[$entry->getFilename()] = $entry->getPathname();
            }
        }

        ksort($skills);

        return $skills;
    }

    /**
     * The marker of a skill directory written by this command, or null.
     *
     * @return array{version: string, hash: string}|null
     */
    protected function readMarker(string $directory): ?array
    {
        $path = $directory.'/'.self::MARKER;

        if (! is_file($path) || $this->isLink($path)) {
            return null;
        }

        $marker = json_decode((string) file_get_contents($path), true);

        if (! is_array($marker) || ! is_string($marker['hash'] ?? null) || ! is_string($marker['version'] ?? null)) {
            return null;
        }

        return ['version' => $marker['version'], 'hash' => $marker['hash']];
    }

    /**
     * A hash over the relative paths and contents of a directory (the marker excluded). Links count as a change.
     */
    protected function hashDirectory(string $directory): string
    {
        $entries = [];

        $walk = function (string $path, string $prefix) use (&$walk, &$entries): void {
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                /** @var SplFileInfo $entry */
                $relative = $prefix.$entry->getFilename();

                if ($relative === self::MARKER) {
                    continue;
                }

                if ($this->isLink($entry->getPathname())) {
                    $entries[$relative] = 'link';
                } elseif ($entry->isDir()) {
                    $entries[$relative.'/'] = 'dir';
                    $walk($entry->getPathname(), $relative.'/');
                } elseif ($entry->isFile()) {
                    $entries[$relative] = (string) hash_file('sha256', $entry->getPathname());
                } else {
                    $entries[$relative] = 'other';
                }
            }
        };

        $walk($directory, '');
        ksort($entries);

        return hash('sha256', (string) json_encode($entries));
    }

    /**
     * Copy the regular files of a directory tree (links are skipped); returns the number of files copied.
     */
    protected function copyDirectory(string $from, string $to): int
    {
        if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
            throw new RuntimeException("Cannot create the directory [{$to}].");
        }

        $count = 0;

        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if ($this->isLink($entry->getPathname()) || $entry->getFilename() === self::MARKER) {
                continue;
            }

            $destination = $to.'/'.$entry->getFilename();

            if ($entry->isDir()) {
                $count += $this->copyDirectory($entry->getPathname(), $destination);
            } elseif ($entry->isFile()) {
                if (! copy($entry->getPathname(), $destination)) {
                    throw new RuntimeException("Cannot write [{$destination}].");
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete a directory tree without following links: a link is removed itself, never what it points to.
     */
    protected function deleteDirectory(string $directory): void
    {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            $path = $entry->getPathname();

            if ($this->isLink($path)) {
                // A directory link or junction on Windows is removed with rmdir().
                if (! @unlink($path) && ! @rmdir($path)) {
                    throw new RuntimeException("Cannot remove the link [{$path}].");
                }
            } elseif ($entry->isDir()) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    /**
     * Add the "larsadmin" server to .mcp.json, keeping every other key; an existing entry needs --force.
     */
    protected function installMcp(): bool
    {
        $path = base_path('.mcp.json');
        $entry = ['command' => 'php', 'args' => ['artisan', 'larsadmin:mcp']];

        if (! $this->safeTarget($path)) {
            throw new InvalidArgumentException('The file [.mcp.json] leads outside the application root.');
        }

        $config = new stdClass;

        $existing = file_exists($path) ? trim((string) file_get_contents($path)) : '';
        $indent = '    ';

        if ($existing !== '') {
            $decoded = json_decode($existing, false, 512, JSON_BIGINT_AS_STRING);

            if (! $decoded instanceof stdClass) {
                $this->components->error('The file [.mcp.json] is not a JSON object; it was left unchanged.');

                return false;
            }

            // Rewriting would silently drop a duplicate key or change a number: explain instead.
            $problem = $this->jsonRewriteProblem($existing);

            if ($problem !== null) {
                $this->components->error("The file [.mcp.json] {$problem}; it was left unchanged. Add the server by hand:");
                $this->line((string) json_encode([McpGate::HANDLE => $entry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return false;
            }

            $config = $decoded;

            if (preg_match('/\n([ \t]+)"/', $existing, $match) === 1) {
                $indent = $match[1];
            }
        }

        $servers = $config->mcpServers ?? new stdClass;

        if (! $servers instanceof stdClass) {
            $this->components->error('The "mcpServers" key in [.mcp.json] is not an object; it was left unchanged.');

            return false;
        }

        if (property_exists($servers, McpGate::HANDLE) && ! $this->option('force')) {
            $this->components->warn('Skipped .mcp.json: the "larsadmin" server is already configured (use --force to replace it).');
        } else {
            $servers->{McpGate::HANDLE} = (object) $entry;
            $config->mcpServers = $servers;

            file_put_contents($path, $this->encodeJson($config, $indent)."\n");

            $this->components->info('Added the "larsadmin" MCP server to .mcp.json.');
        }

        $this->line('For other MCP clients, register the same stdio server:');
        $this->line((string) json_encode([McpGate::HANDLE => $entry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! McpGate::mcpInstalled()) {
            $this->components->warn('The server will not start until laravel/mcp is installed: composer require laravel/mcp --dev');
        }

        return true;
    }

    /**
     * Pretty-printed JSON without escaped slashes or unicode, indented with the given unit (4 spaces by default).
     */
    protected function encodeJson(stdClass $value, string $indent): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if ($indent === '    ') {
            return $json;
        }

        // JSON strings hold no raw line breaks, so every leading run of spaces is indentation.
        return (string) preg_replace_callback('/^((?: {4})+)/m', fn (array $m) => str_repeat($indent, intdiv(strlen($m[1]), 4)), $json);
    }

    /**
     * Why a JSON document cannot be decoded and re-encoded without changing it: a duplicate key in an object (the
     * decoder keeps only the last one) or a number PHP cannot hold exactly (an integer beyond the native range,
     * a non-finite or over-precise float). Null when a rewrite is safe. Expects valid JSON.
     */
    protected function jsonRewriteProblem(string $json): ?string
    {
        /** @var list<array{keys: array<string, true>, expectKey: bool}|null> $stack null = array */
        $stack = [];
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                $start = $i;

                for ($i++; $i < $length && $json[$i] !== '"'; $i++) {
                    if ($json[$i] === '\\') {
                        $i++;
                    }
                }

                $top = array_key_last($stack);

                if ($top !== null && $stack[$top] !== null && $stack[$top]['expectKey']) {
                    $key = (string) json_decode(substr($json, $start, $i - $start + 1));

                    if (isset($stack[$top]['keys'][$key])) {
                        return "has the duplicate key \"{$key}\"";
                    }

                    $stack[$top]['keys'][$key] = true;
                    $stack[$top]['expectKey'] = false;
                }
            } elseif ($char === '{') {
                $stack[] = ['keys' => [], 'expectKey' => true];
            } elseif ($char === '[') {
                $stack[] = null;
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($char === ',') {
                $top = array_key_last($stack);

                if ($top !== null && $stack[$top] !== null) {
                    $stack[$top]['expectKey'] = true;
                }
            } elseif ($char === '-' || ctype_digit($char)) {
                preg_match('/-?\d+(\.\d+)?([eE][+-]?\d+)?/A', $json, $match, 0, $i);
                $number = $match[0];
                $i += strlen($number) - 1;

                if ($this->numberLosesPrecision($number)) {
                    return "has the number {$number}, which cannot be kept exactly";
                }
            }
        }

        return null;
    }

    /**
     * Whether PHP would change a JSON number on a decode/encode round trip.
     */
    protected function numberLosesPrecision(string $number): bool
    {
        if (preg_match('/^-?\d+\z/', $number) === 1) {
            return ! is_int(json_decode($number, false, 512, JSON_BIGINT_AS_STRING));
        }

        if (! is_finite((float) $number)) {
            return true;
        }

        // More than 17 significant digits do not survive a double.
        $digits = ltrim(str_replace(['-', '.'], '', (string) preg_replace('/[eE].*\z/', '', $number)), '0');

        return strlen(rtrim($digits, '0')) > 17;
    }

    /**
     * Append the guidelines import to CLAUDE.md once; the existing text is never changed.
     */
    protected function installClaudeMd(): bool
    {
        $path = base_path('CLAUDE.md');

        if (! $this->safeTarget($path)) {
            throw new InvalidArgumentException('The file [CLAUDE.md] leads outside the application root.');
        }

        $source = $this->claudeMdGuidelines();

        if ($source === 'import') {
            $this->components->info('CLAUDE.md already imports the larsadmin guidelines; left unchanged.');

            return true;
        }

        if ($source === 'boost') {
            $this->components->info('CLAUDE.md already contains the larsadmin guidelines inserted by Laravel Boost; left unchanged (update them with `php artisan boost:update`).');

            return true;
        }

        $contents = file_exists($path) ? (string) file_get_contents($path) : '';
        $prefix = $contents === '' ? '' : (preg_match('/\R\z/', $contents) === 1 ? "\n" : "\n\n");

        file_put_contents($path, $contents.$prefix.self::CLAUDE_IMPORT."\n");

        $this->components->info('Imported the larsadmin guidelines in CLAUDE.md.');

        return true;
    }

    /**
     * How CLAUDE.md already loads the larsadmin guidelines: "import" (our @-import line), "boost" (the block
     * Laravel Boost inserts, headed "=== maxcxam/larsadmin/core rules ==="), or null when it does not.
     */
    protected function claudeMdGuidelines(): ?string
    {
        $path = base_path('CLAUDE.md');

        if (! is_file($path) || ! $this->safeTarget($path)) {
            return null;
        }

        $boost = false;

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);

            if ($line === self::CLAUDE_IMPORT) {
                return 'import';
            }

            $boost = $boost || preg_match('/^===\s*maxcxam\/larsadmin\/core\s+rules\s*===$/', $line) === 1;
        }

        return $boost ? 'boost' : null;
    }

    /**
     * The skill names Laravel Boost installed, from the "skills" list of its boost.json; empty without Boost.
     *
     * @return list<string>
     */
    protected function boostSkills(): array
    {
        $path = base_path('boost.json');

        if (! is_file($path) || ! $this->safeTarget($path)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($path), true);
        $skills = is_array($config) && is_array($config['skills'] ?? null) ? $config['skills'] : [];

        return array_values(array_filter($skills, 'is_string'));
    }
}
