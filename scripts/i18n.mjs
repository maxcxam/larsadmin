// Regenerates resources/js/i18n/uk.json (the bundled fallback translations) from lang/uk/ui.php.
// Run `npm run i18n` after editing lang/uk/ui.php; tests/Feature/UiTranslationsTest.php fails when it is stale.
import { execFileSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))

const script = `<?php
$flat = function (array $lines, string $prefix = '') use (&$flat): array {
    $out = [];
    foreach ($lines as $key => $value) {
        is_array($value) ? $out += $flat($value, $prefix.$key.'.') : $out[$prefix.$key] = (string) $value;
    }
    return $out;
};
echo json_encode($flat(require 'lang/uk/ui.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
`

const json = execFileSync('php', [], { cwd: root, input: script, encoding: 'utf8', shell: process.platform === 'win32' })

writeFileSync(new URL('../resources/js/i18n/uk.json', import.meta.url), `${json}\n`)
console.log('resources/js/i18n/uk.json written')
