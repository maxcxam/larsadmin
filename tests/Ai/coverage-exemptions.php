<?php

/*
|--------------------------------------------------------------------------
| Skill coverage exemptions (ADR-040)
|--------------------------------------------------------------------------
|
| Elements of the public surface that SkillCoverageTest does not require a
| skill to mention, as "element => reason". The element is written exactly as
| the failing test prints it, e.g.:
|
|   'guide docs/guide/en/11-ui.md' => 'Why no skill links this page.',
|   'command larsadmin:example' => '...',
|   'config larsadmin.example.key' => '...',
|   'env LARSADMIN_EXAMPLE' => '...',
|   'class Maxcxam\Larsadmin\Example' => '...',
|   'facade Larsadmin::example' => '...',
|   'js useExample' => '...',
|   'invariant Maxcxam\Larsadmin\Example' => '...',
|
| Every entry needs a reason and must name an element that still exists.
| The list must be empty when a release is tagged.
|
*/

return [
];
