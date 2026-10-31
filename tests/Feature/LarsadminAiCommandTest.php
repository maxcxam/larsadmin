<?php

use Illuminate\Filesystem\Filesystem;
use Maxcxam\Larsadmin\Console\AiCommand;
use Maxcxam\Larsadmin\Mcp\McpGate;

beforeEach(function () {
    $unique = getmypid().'-'.random_int(1, PHP_INT_MAX);
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ai-'.$unique;
    $this->source = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ai-skills-'.$unique;
    $this->links = [];

    mkdir($this->root, 0777, true);
    mkdir($this->source.'/larsadmin-alpha/references', 0777, true);
    mkdir($this->source.'/larsadmin-beta', 0777, true);
    mkdir($this->source.'/other-skill', 0777, true);
    file_put_contents($this->source.'/larsadmin-alpha/SKILL.md', "---\nname: larsadmin-alpha\n---\n# Alpha\n");
    file_put_contents($this->source.'/larsadmin-alpha/references/api.md', "# Alpha API\n");
    file_put_contents($this->source.'/larsadmin-beta/SKILL.md', "---\nname: larsadmin-beta\n---\n# Beta\n");
    file_put_contents($this->source.'/other-skill/SKILL.md', "# Not ours\n");
    file_put_contents($this->source.'/README.md', "Not a skill\n");

    AiCommand::$skillsSource = $this->source;
    $this->originalBase = $this->app->basePath();
    $this->app->setBasePath($this->root);
});

afterEach(function () {
    // Links first: deleting a tree that still holds a link could reach what it points to.
    foreach ($this->links as $link) {
        PHP_OS_FAMILY === 'Windows' ? (@rmdir($link) || @unlink($link)) : @unlink($link);
    }

    AiCommand::$skillsSource = null;
    McpGate::$mcpInstalled = null;
    $this->app->setBasePath($this->originalBase);
    (new Filesystem)->deleteDirectory($this->root);
    (new Filesystem)->deleteDirectory($this->source);
});

/**
 * Create a directory link (a symlink, or a junction on Windows without symlink rights); false when impossible.
 */
function aiCommandLink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>NUL', $output, $code);

        return $code === 0;
    }

    return false;
}

it('copies only the larsadmin-* skills with a marker', function () {
    $this->artisan('larsadmin:ai', ['--skills' => true])->assertSuccessful();

    $skills = $this->root.'/.claude/skills';

    expect(file_get_contents($skills.'/larsadmin-alpha/SKILL.md'))->toBe(file_get_contents($this->source.'/larsadmin-alpha/SKILL.md'))
        ->and(file_get_contents($skills.'/larsadmin-alpha/references/api.md'))->toBe("# Alpha API\n")
        ->and(file_exists($skills.'/larsadmin-beta/SKILL.md'))->toBeTrue()
        ->and(file_exists($skills.'/other-skill'))->toBeFalse()
        ->and(file_exists($skills.'/README.md'))->toBeFalse();

    $marker = json_decode(file_get_contents($skills.'/larsadmin-alpha/'.AiCommand::MARKER), true);

    expect($marker['package'])->toBe('maxcxam/larsadmin')
        ->and($marker['version'])->toBe(McpGate::packageVersion())
        ->and($marker['hash'])->toBeString();
});

it('updates its own unchanged skills on a second run', function () {
    $this->artisan('larsadmin:ai', ['--skills' => true])->assertSuccessful();

    file_put_contents($this->source.'/larsadmin-alpha/SKILL.md', "# Alpha v2\n");
    unlink($this->source.'/larsadmin-alpha/references/api.md');

    $this->artisan('larsadmin:ai', ['--skills' => true])->assertSuccessful();

    expect(file_get_contents($this->root.'/.claude/skills/larsadmin-alpha/SKILL.md'))->toBe("# Alpha v2\n")
        ->and(file_exists($this->root.'/.claude/skills/larsadmin-alpha/references/api.md'))->toBeFalse();
});

it('leaves skills changed by hand alone unless forced', function () {
    $this->artisan('larsadmin:ai', ['--skills' => true])->assertSuccessful();

    $edited = $this->root.'/.claude/skills/larsadmin-alpha/SKILL.md';
    file_put_contents($edited, "# My notes\n");
    file_put_contents($this->source.'/larsadmin-alpha/SKILL.md', "# Alpha v2\n");

    $this->artisan('larsadmin:ai', ['--skills' => true])
        ->expectsOutputToContain('changed by hand')
        ->assertSuccessful();

    expect(file_get_contents($edited))->toBe("# My notes\n");

    $this->artisan('larsadmin:ai', ['--skills' => true, '--force' => true])->assertSuccessful();

    expect(file_get_contents($edited))->toBe("# Alpha v2\n");
});

it('does not touch directories it did not create unless forced', function () {
    $foreign = $this->root.'/.claude/skills/larsadmin-beta';
    mkdir($foreign, 0777, true);
    file_put_contents($foreign.'/SKILL.md', "# Someone else's\n");

    $this->artisan('larsadmin:ai', ['--skills' => true])
        ->expectsOutputToContain('not created by larsadmin:ai')
        ->assertSuccessful();

    expect(file_get_contents($foreign.'/SKILL.md'))->toBe("# Someone else's\n")
        ->and(file_exists($foreign.'/'.AiCommand::MARKER))->toBeFalse()
        ->and(file_exists($this->root.'/.claude/skills/larsadmin-alpha/SKILL.md'))->toBeTrue();

    $this->artisan('larsadmin:ai', ['--skills' => true, '--force' => true])->assertSuccessful();

    expect(file_get_contents($foreign.'/SKILL.md'))->toBe("---\nname: larsadmin-beta\n---\n# Beta\n");
});

it('copies into a custom path', function () {
    $this->artisan('larsadmin:ai', ['--skills' => true, '--path' => '.cursor/skills'])->assertSuccessful();

    expect(file_exists($this->root.'/.cursor/skills/larsadmin-alpha/SKILL.md'))->toBeTrue();
});

it('refuses skill paths outside the application root', function (string $path) {
    $this->artisan('larsadmin:ai', ['--skills' => true, '--path' => $path])->assertFailed();

    expect(file_exists(dirname($this->root).'/escaped'))->toBeFalse();
})->with([
    'parent' => ['../escaped'],
    'absolute' => ['/tmp/escaped'],
    'drive' => ['C:\\escaped'],
    'backslash' => ['..\\escaped'],
]);

it('refuses to write skills through a link', function () {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ai-outside-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($outside, 0777, true);
    mkdir($this->root.'/.claude', 0777, true);

    if (! aiCommandLink($outside, $this->root.'/.claude/skills')) {
        (new Filesystem)->deleteDirectory($outside);
        $this->markTestSkipped('Links are not available.');
    }

    $this->links[] = $this->root.'/.claude/skills';

    try {
        $this->artisan('larsadmin:ai', ['--skills' => true])->assertFailed();

        expect(glob($outside.'/*'))->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($outside);
    }
});

it('refuses a skill directory that is itself a link, even with --force', function () {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ai-target-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($outside, 0777, true);
    file_put_contents($outside.'/keep.txt', 'keep');
    mkdir($this->root.'/.claude/skills', 0777, true);
    $link = $this->root.'/.claude/skills/larsadmin-alpha';

    if (! aiCommandLink($outside, $link)) {
        (new Filesystem)->deleteDirectory($outside);
        $this->markTestSkipped('Links are not available.');
    }

    $this->links[] = $link;

    try {
        $this->artisan('larsadmin:ai', ['--skills' => true, '--force' => true])->assertFailed();

        expect(scandir($outside))->toBe(['.', '..', 'keep.txt'])
            ->and(file_exists($this->root.'/.claude/skills/larsadmin-beta'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($outside);
    }
});

it('removes a link inside its own skill without following it', function () {
    $this->artisan('larsadmin:ai', ['--skills' => true])->assertSuccessful();

    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ai-keep-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($outside, 0777, true);
    file_put_contents($outside.'/keep.txt', 'keep');
    $link = $this->root.'/.claude/skills/larsadmin-alpha/linked';

    if (! aiCommandLink($outside, $link)) {
        (new Filesystem)->deleteDirectory($outside);
        $this->markTestSkipped('Links are not available.');
    }

    $this->links[] = $link;

    try {
        // The link is a change by hand: skipped without --force, removed (not followed) with it.
        $this->artisan('larsadmin:ai', ['--skills' => true])->expectsOutputToContain('changed by hand')->assertSuccessful();
        $this->artisan('larsadmin:ai', ['--skills' => true, '--force' => true])->assertSuccessful();

        expect(file_get_contents($outside.'/keep.txt'))->toBe('keep')
            ->and(file_exists($link))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($outside);
    }
});

it('fails when the package has no skills', function () {
    AiCommand::$skillsSource = $this->root.'/missing';

    $this->artisan('larsadmin:ai', ['--skills' => true])
        ->expectsOutputToContain('No larsadmin skills found')
        ->assertFailed();
});

it('creates .mcp.json with the larsadmin server', function () {
    $this->artisan('larsadmin:ai', ['--mcp' => true])->assertSuccessful();

    $config = json_decode(file_get_contents($this->root.'/.mcp.json'), true);

    expect($config)->toBe(['mcpServers' => ['larsadmin' => ['command' => 'php', 'args' => ['artisan', 'larsadmin:mcp']]]]);
});

it('merges into .mcp.json and keeps the other servers and keys', function () {
    file_put_contents($this->root.'/.mcp.json', json_encode([
        'mcpServers' => [
            'laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']],
            'empty' => ['command' => 'x', 'env' => new stdClass],
        ],
        'other' => true,
    ]));

    $this->artisan('larsadmin:ai', ['--mcp' => true])->assertSuccessful();

    $raw = file_get_contents($this->root.'/.mcp.json');
    $config = json_decode($raw, true);

    expect($config['mcpServers']['laravel-boost'])->toBe(['command' => 'php', 'args' => ['artisan', 'boost:mcp']])
        ->and($config['mcpServers']['larsadmin']['args'])->toBe(['artisan', 'larsadmin:mcp'])
        ->and($config['other'])->toBeTrue()
        ->and($raw)->toContain('"env": {}');
});

it('writes .mcp.json without escapes, in the file\'s indentation, with a trailing newline', function () {
    file_put_contents($this->root.'/.mcp.json', "{\n  \"mcpServers\": {\n    \"other\": {\"command\": \"/usr/bin/tool\", \"args\": [\"Привіт\"], \"ratio\": 1.0}\n  }\n}");

    $this->artisan('larsadmin:ai', ['--mcp' => true])->assertSuccessful();

    $raw = file_get_contents($this->root.'/.mcp.json');

    expect($raw)->toContain('"/usr/bin/tool"')
        ->toContain('"Привіт"')
        ->toContain('"ratio": 1.0')
        ->toContain("\n  \"mcpServers\": {\n    \"other\": {\n      \"command\"")
        ->toEndWith("}\n")
        ->not->toContain('\\u')
        ->not->toContain('\\/')
        ->and(json_decode($raw, true)['mcpServers']['larsadmin']['command'])->toBe('php');
});

it('refuses to rewrite .mcp.json it cannot keep exactly', function (string $contents, string $reason) {
    file_put_contents($this->root.'/.mcp.json', $contents);

    $this->artisan('larsadmin:ai', ['--mcp' => true, '--force' => true])
        ->expectsOutputToContain($reason)
        ->expectsOutputToContain('larsadmin:mcp')
        ->assertFailed();

    expect(file_get_contents($this->root.'/.mcp.json'))->toBe($contents);
})->with([
    'duplicate server' => ['{"mcpServers": {"a": {"command": "x"}, "a": {"command": "y"}}}', 'duplicate key "a"'],
    'duplicate top-level key' => ['{"x": 1, "mcpServers": {}, "x": 2}', 'duplicate key "x"'],
    'big integer' => ['{"mcpServers": {}, "id": 123456789012345678901234567890}', '123456789012345678901234567890'],
    'over-precise float' => ['{"mcpServers": {}, "f": 0.123456789012345678901}', '0.123456789012345678901'],
    'infinite float' => ['{"mcpServers": {}, "f": 1e400}', '1e400'],
]);

it('accepts the same key in different objects and keys inside strings', function () {
    file_put_contents($this->root.'/.mcp.json', '{"mcpServers": {"a": {"command": "x", "env": {"command": "{\"a\": 1, \"a\": 2}"}}}, "n": -12.5e3}');

    $this->artisan('larsadmin:ai', ['--mcp' => true])->assertSuccessful();

    expect(json_decode(file_get_contents($this->root.'/.mcp.json'), true)['mcpServers'])->toHaveKeys(['a', 'larsadmin']);
});

it('keeps an existing larsadmin entry unless forced', function () {
    file_put_contents($this->root.'/.mcp.json', json_encode(['mcpServers' => ['larsadmin' => ['command' => 'custom']]]));

    $this->artisan('larsadmin:ai', ['--mcp' => true])
        ->expectsOutputToContain('already configured')
        ->assertSuccessful();

    expect(json_decode(file_get_contents($this->root.'/.mcp.json'), true)['mcpServers']['larsadmin'])->toBe(['command' => 'custom']);

    $this->artisan('larsadmin:ai', ['--mcp' => true, '--force' => true])->assertSuccessful();

    expect(json_decode(file_get_contents($this->root.'/.mcp.json'), true)['mcpServers']['larsadmin']['command'])->toBe('php');
});

it('leaves an invalid .mcp.json unchanged', function (string $contents) {
    file_put_contents($this->root.'/.mcp.json', $contents);

    $this->artisan('larsadmin:ai', ['--mcp' => true, '--force' => true])->assertFailed();

    expect(file_get_contents($this->root.'/.mcp.json'))->toBe($contents);
})->with([
    'broken' => ['{"mcpServers": '],
    'list' => ['[1, 2]'],
    'servers not an object' => ['{"mcpServers": [1]}'],
]);

it('warns that the server needs laravel/mcp', function () {
    McpGate::$mcpInstalled = false;

    $this->artisan('larsadmin:ai', ['--mcp' => true])
        ->expectsOutputToContain('composer require laravel/mcp --dev')
        ->assertSuccessful();

    expect(file_exists($this->root.'/.mcp.json'))->toBeTrue();
});

it('refuses to write .mcp.json through a link', function () {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ai-mcp-'.getmypid().'-'.random_int(1, PHP_INT_MAX).'.json';
    file_put_contents($outside, '{}');

    if (! @symlink($outside, $this->root.'/.mcp.json')) {
        @unlink($outside);
        $this->markTestSkipped('File links are not available.');
    }

    $this->links[] = $this->root.'/.mcp.json';

    try {
        $this->artisan('larsadmin:ai', ['--mcp' => true])->assertFailed();

        expect(file_get_contents($outside))->toBe('{}');
    } finally {
        @unlink($outside);
    }
});

it('imports the guidelines in CLAUDE.md once and keeps the existing text', function () {
    file_put_contents($this->root.'/CLAUDE.md', "# My project\n\nRules.");

    $this->artisan('larsadmin:ai', ['--claude-md' => true])->assertSuccessful();
    $this->artisan('larsadmin:ai', ['--claude-md' => true])
        ->expectsOutputToContain('already imports')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/CLAUDE.md'))->toBe("# My project\n\nRules.\n\n".AiCommand::CLAUDE_IMPORT."\n")
        ->and(file_exists($this->root.'/.mcp.json'))->toBeFalse()
        ->and(file_exists($this->root.'/.claude'))->toBeFalse();
});

it('does not duplicate the guidelines Laravel Boost inserted in CLAUDE.md', function () {
    $boost = "<laravel-boost-guidelines>\n=== maxcxam/larsadmin/core rules ===\n\n# Larsadmin\n</laravel-boost-guidelines>\n";
    file_put_contents($this->root.'/CLAUDE.md', $boost);

    $this->artisan('larsadmin:ai', ['--claude-md' => true])
        ->expectsOutputToContain('inserted by Laravel Boost')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/CLAUDE.md'))->toBe($boost);

    $this->artisan('larsadmin:ai', ['--mcp' => true])
        ->doesntExpectOutputToContain('Tip: add the line')
        ->assertSuccessful();
});

it('does not suggest the import when CLAUDE.md already has it', function () {
    file_put_contents($this->root.'/CLAUDE.md', "# Project\n".AiCommand::CLAUDE_IMPORT."\n");

    $this->artisan('larsadmin:ai', ['--mcp' => true])
        ->doesntExpectOutputToContain('Tip: add the line')
        ->assertSuccessful();
});

it('suggests the import when CLAUDE.md has neither the import nor the Boost guidelines', function () {
    file_put_contents($this->root.'/CLAUDE.md', "# Project\n\n=== laravel/core rules ===\n");

    $this->artisan('larsadmin:ai', ['--mcp' => true])
        ->expectsOutputToContain('Tip: add the line')
        ->assertSuccessful();
});

it('points skills installed by Laravel Boost to boost:update', function () {
    file_put_contents($this->root.'/boost.json', json_encode(['skills' => ['larsadmin-alpha']]));
    mkdir($this->root.'/.claude/skills/larsadmin-alpha', 0777, true);
    mkdir($this->root.'/.claude/skills/larsadmin-beta', 0777, true);
    file_put_contents($this->root.'/.claude/skills/larsadmin-alpha/SKILL.md', "# From Boost\n");
    file_put_contents($this->root.'/.claude/skills/larsadmin-beta/SKILL.md', "# Someone else's\n");

    $this->artisan('larsadmin:ai', ['--skills' => true])
        ->expectsOutputToContain('installed by Laravel Boost. Update it with `php artisan boost:update`, or pass --force to take it over')
        ->expectsOutputToContain('larsadmin-beta: it was not created by larsadmin:ai')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/.claude/skills/larsadmin-alpha/SKILL.md'))->toBe("# From Boost\n");

    $this->artisan('larsadmin:ai', ['--skills' => true, '--force' => true])->assertSuccessful();

    expect(file_exists($this->root.'/.claude/skills/larsadmin-alpha/'.AiCommand::MARKER))->toBeTrue();
});

it('creates CLAUDE.md when missing', function () {
    $this->artisan('larsadmin:ai', ['--claude-md' => true])->assertSuccessful();

    expect(file_get_contents($this->root.'/CLAUDE.md'))->toBe(AiCommand::CLAUDE_IMPORT."\n");
});

it('installs the skills and the MCP entry without interaction and prints the CLAUDE.md hint', function () {
    $this->artisan('larsadmin:ai', ['--no-interaction' => true])
        ->expectsOutputToContain(AiCommand::CLAUDE_IMPORT)
        ->assertSuccessful();

    expect(file_exists($this->root.'/.claude/skills/larsadmin-alpha/SKILL.md'))->toBeTrue()
        ->and(file_exists($this->root.'/.mcp.json'))->toBeTrue()
        ->and(file_exists($this->root.'/CLAUDE.md'))->toBeFalse();
});

it('asks what to install when interactive', function () {
    $this->artisan('larsadmin:ai')
        ->expectsChoice('What should be installed for your AI assistant?', ['claude-md'], [
            'skills' => 'Skills (.claude/skills/larsadmin-*)',
            'mcp' => 'MCP server entry (.mcp.json)',
            'claude-md' => 'Guidelines import in CLAUDE.md',
        ])
        ->assertSuccessful();

    expect(file_exists($this->root.'/CLAUDE.md'))->toBeTrue()
        ->and(file_exists($this->root.'/.mcp.json'))->toBeFalse()
        ->and(file_exists($this->root.'/.claude'))->toBeFalse();
});
