<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Golden parity of the generators (ADR-039, design 1.6 §11 M4): the files and console output of every artisan
 * generator, captured before the commands were moved onto the shared Scaffolder, must stay byte-identical.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-parity-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($this->root.'/app', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $this->originalBase = $this->app->basePath();
    $this->app->setBasePath($this->root);
    $this->columns = getenv('COLUMNS');
    putenv('COLUMNS=1000');
});

afterEach(function () {
    $this->app->setBasePath($this->originalBase);
    putenv($this->columns === false ? 'COLUMNS' : 'COLUMNS='.$this->columns);
    (new Filesystem)->deleteDirectory($this->root);
});

/**
 * The scenario: [command, arguments] in order, or ['mkdir', directory] to prepare the application.
 *
 * @return list<array{0: string, 1: array<string, mixed>|string}>
 */
function parityScenario(): array
{
    return [
        ['larsadmin:entity', ['name' => 'Post']],
        ['mkdir', 'app/Models'],
        ['larsadmin:entity', ['name' => 'Admin/Comment', '--model' => 'Comment']],
        ['larsadmin:entity', ['name' => 'Member', '--model' => 'App\\Models\\User']],
        ['larsadmin:entity', ['name' => 'Post']],
        ['larsadmin:entity', ['name' => 'Post', '--force' => true, '--model' => 'Article']],
        ['larsadmin:entity', ['name' => 'Class']],
        ['larsadmin:action', ['name' => 'PublishPost']],
        ['larsadmin:action', ['name' => 'DeletePosts', '--destructive' => true]],
        ['larsadmin:filter', ['name' => 'Status']],
        ['larsadmin:filter', ['name' => 'Region.php']],
        ['larsadmin:dashboard', ['name' => 'Sales']],
        ['larsadmin:value', ['name' => 'NewUsers']],
        ['larsadmin:value', ['name' => 'App\\Larsadmin\\Metrics\\Revenue']],
        ['larsadmin:trend', ['name' => 'UsersPerDay']],
        ['larsadmin:partition', ['name' => 'UsersByRole']],
        ['larsadmin:progress', ['name' => 'Goal']],
        ['larsadmin:progress', ['name' => 'Goal']],
        ['larsadmin:field', ['name' => 'star-rating']],
        ['larsadmin:field', ['name' => 'StarRating']],
        ['larsadmin:field', ['name' => '1Rating']],
        ['larsadmin:card', ['name' => 'Sales']],
        ['larsadmin:card', ['name' => 'Stats', '--data' => true]],
        ['larsadmin:card', ['name' => 'WelcomeCard']],
        ['larsadmin:tool', ['name' => 'Backups']],
        ['larsadmin:tool', ['name' => 'SystemPage']],
        ['larsadmin:extension', ['name' => 'acme/larsadmin-rating']],
        ['larsadmin:extension', ['name' => 'acme.co/stars', '--path' => 'extensions/local', '--field' => 'star-rating', '--card' => 'TopRated']],
        ['larsadmin:extension', ['name' => 'acme/larsadmin-rating']],
        ['larsadmin:extension', ['name' => 'Acme/Rating']],
        ['larsadmin:extension', ['name' => 'acme/x', '--path' => '../outside']],
        ['larsadmin:extension', ['name' => 'acme/x', '--field' => 'Same', '--card' => 'Same']],
        ['larsadmin:field', ['name' => 'Mood', '--extension' => 'packages/acme/larsadmin-rating']],
        ['larsadmin:card', ['name' => 'Top', '--extension' => 'packages/acme/larsadmin-rating', '--data' => true]],
        ['larsadmin:tool', ['name' => 'Reports', '--extension' => 'packages/acme/larsadmin-rating']],
        ['larsadmin:tool', ['name' => 'Reports', '--extension' => 'packages/acme/larsadmin-rating', '--force' => true]],
        ['larsadmin:field', ['name' => 'Mood', '--extension' => 'packages/missing']],
    ];
}

/**
 * Console output without the temporary root, with forward slashes in its paths and without the dev-version
 * hint of larsadmin:extension (it depends on how the package is installed, not on the generator).
 */
function parityOutput(string $output, string $root): string
{
    $output = str_replace("\r\n", "\n", $output);

    foreach ([$root, str_replace('\\', '/', $root), str_replace('/', '\\', $root)] as $prefix) {
        $output = preg_replace_callback('/'.preg_quote($prefix, '/').'[^\s\]]*/', fn (array $m) => '{root}'.str_replace('\\', '/', substr($m[0], strlen($prefix))), $output);
    }

    $lines = array_filter(explode("\n", $output), fn (string $line) => ! preg_match('/Larsadmin is installed as|Only the main branch is aliased|"options": \{"versions"|allow dev stability/', $line));

    return trim(implode("\n", array_map('rtrim', $lines)));
}

/**
 * Every file below a directory, relative path => sha256 of the contents (line endings normalised).
 *
 * @return array<string, string>
 */
function parityTree(string $root): array
{
    $files = [];

    foreach ((new Filesystem)->allFiles($root, true) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if ($path !== 'composer.json') {
            $files[$path] = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname())));
        }
    }

    ksort($files);

    return $files;
}

/**
 * Run the scenario with the artisan commands; the exit codes and outputs of the steps, and the resulting tree.
 *
 * @return array{steps: list<string>, files: array<string, string>}
 */
function runParityScenario(string $root): array
{
    $steps = [];

    foreach (parityScenario() as [$command, $arguments]) {
        if ($command === 'mkdir') {
            mkdir($root.'/'.$arguments, 0777, true);

            continue;
        }

        $output = new BufferedOutput;
        $code = Artisan::call($command, $arguments, $output);
        $steps[] = $code.' '.$command.' '.json_encode($arguments, JSON_UNESCAPED_SLASHES).' => '.parityOutput($output->fetch(), $root);
    }

    return ['steps' => $steps, 'files' => parityTree($root)];
}

it('keeps the artisan generators byte-identical', function () {
    $result = runParityScenario($this->root);

    if (($dump = getenv('LARSADMIN_PARITY_DUMP')) !== false && $dump !== '') {
        file_put_contents($dump, var_export($result, true));
    }

    expect($result)->toBe(parityGolden());
});

it('produces the golden files through the Scaffolder directly, as the MCP tool does', function () {
    $scaffolder = app(Scaffolder::class);
    $options = ['--model' => 'model', '--destructive' => 'destructive', '--extension' => 'extension', '--path' => 'path', '--field' => 'field', '--card' => 'card'];

    foreach (parityScenario() as [$command, $arguments]) {
        if ($command === 'mkdir') {
            mkdir($this->root.'/'.$arguments, 0777, true);

            continue;
        }

        $kind = substr($command, strlen('larsadmin:'));
        $kind = $kind === 'card' && ($arguments['--data'] ?? false) ? 'data-card' : $kind;
        // The commands strip ".php" from the name and refuse names reserved by PHP before the Scaffolder runs.
        $name = preg_replace('/\.php\z/', '', $arguments['name']);

        if ($name === 'Class') {
            continue;
        }

        $mapped = [];

        foreach ($arguments as $key => $value) {
            if (isset($options[$key])) {
                $mapped[$options[$key]] = $value;
            }
        }

        try {
            $scaffolder->write($scaffolder->plan($kind, $name, $mapped), (bool) ($arguments['--force'] ?? false));
        } catch (InvalidArgumentException) {
            // The steps the commands refuse (existing files, invalid names) write nothing here either.
        }
    }

    expect(parityTree($this->root))->toBe(parityGolden()['files']);
});

it('plans in strict mode what the commands write for strict-valid input', function () {
    $scaffolder = app(Scaffolder::class);

    foreach ([
        ['entity', 'Blog/Post', ['model' => 'App\\Models\\Article']],
        ['action', 'Publish', ['destructive' => false]],
        ['card', 'Sales', []],
        ['extension', 'acme/stars', ['field' => 'StarRating', 'card' => 'TopRated']],
    ] as [$kind, $name, $options]) {
        $loose = $scaffolder->plan($kind, $name, $options);
        $strict = $scaffolder->plan($kind, $name, $options, strict: true);

        expect($strict->toArray())->toBe($loose->toArray())
            ->and($strict->files)->not->toBeEmpty();
    }
});

/**
 * The golden: captured from the 1.5 generators (GeneratorCommand / ExtensionScaffold) before the refactoring.
 *
 * @return array{steps: list<string>, files: array<string, string>}
 */
function parityGolden(): array
{
    return [
        'steps' => [
            0 => '0 larsadmin:entity {"name":"Post"} => INFO  Entity [{root}/app/Larsadmin/Post.php] created successfully.',
            1 => '0 larsadmin:entity {"name":"Admin/Comment","--model":"Comment"} => INFO  Entity [{root}/app/Larsadmin/Admin/Comment.php] created successfully.',
            2 => '0 larsadmin:entity {"name":"Member","--model":"App\\\\Models\\\\User"} => INFO  Entity [{root}/app/Larsadmin/Member.php] created successfully.',
            3 => '0 larsadmin:entity {"name":"Post"} => ERROR  Entity already exists.',
            4 => '0 larsadmin:entity {"name":"Post","--force":true,"--model":"Article"} => INFO  Entity [{root}/app/Larsadmin/Post.php] created successfully.',
            5 => '0 larsadmin:entity {"name":"Class"} => ERROR  The name "Class" is reserved by PHP.',
            6 => '0 larsadmin:action {"name":"PublishPost"} => INFO  Action [{root}/app/Larsadmin/Actions/PublishPost.php] created successfully.',
            7 => '0 larsadmin:action {"name":"DeletePosts","--destructive":true} => INFO  Action [{root}/app/Larsadmin/Actions/DeletePosts.php] created successfully.',
            8 => '0 larsadmin:filter {"name":"Status"} => INFO  Filter [{root}/app/Larsadmin/Filters/Status.php] created successfully.',
            9 => '0 larsadmin:filter {"name":"Region.php"} => INFO  Filter [{root}/app/Larsadmin/Filters/Region.php] created successfully.',
            10 => '0 larsadmin:dashboard {"name":"Sales"} => INFO  Dashboard [{root}/app/Larsadmin/Dashboards/Sales.php] created successfully.',
            11 => '0 larsadmin:value {"name":"NewUsers"} => INFO  Value metric [{root}/app/Larsadmin/Metrics/NewUsers.php] created successfully.',
            12 => '0 larsadmin:value {"name":"App\\\\Larsadmin\\\\Metrics\\\\Revenue"} => INFO  Value metric [{root}/app/Larsadmin/Metrics/Revenue.php] created successfully.',
            13 => '0 larsadmin:trend {"name":"UsersPerDay"} => INFO  Trend metric [{root}/app/Larsadmin/Metrics/UsersPerDay.php] created successfully.',
            14 => '0 larsadmin:partition {"name":"UsersByRole"} => INFO  Partition metric [{root}/app/Larsadmin/Metrics/UsersByRole.php] created successfully.',
            15 => '0 larsadmin:progress {"name":"Goal"} => INFO  Progress metric [{root}/app/Larsadmin/Metrics/Goal.php] created successfully.',
            16 => '0 larsadmin:progress {"name":"Goal"} => ERROR  Progress metric already exists.',
            17 => '0 larsadmin:field {"name":"star-rating"} => INFO  Field [StarRating] created with the component key [app/star-rating].

  Register it in resources/larsadmin/js/extension.js:

    import StarRatingInput from \'./fields/StarRatingInput.vue\'
    import StarRatingDisplay from \'./fields/StarRatingDisplay.vue\'
    fields: { \'app/star-rating\': { form: StarRatingInput, display: StarRatingDisplay } },',
            18 => '1 larsadmin:field {"name":"StarRating"} => ERROR  Already exists: app/Larsadmin/Fields/StarRating.php, resources/larsadmin/js/fields/StarRatingInput.vue, resources/larsadmin/js/fields/StarRatingDisplay.vue. Use --force to overwrite.',
            19 => '1 larsadmin:field {"name":"1Rating"} => ERROR  The name [1Rating] must start with a letter and contain letters and digits only.',
            20 => '0 larsadmin:card {"name":"Sales"} => INFO  Card [Sales] created with the component key [app/sales].

  Register it in resources/larsadmin/js/extension.js:

    import SalesCard from \'./cards/SalesCard.vue\'
    cards: { \'app/sales\': SalesCard },',
            21 => '0 larsadmin:card {"name":"Stats","--data":true} => INFO  Card [Stats] created with the component key [app/stats].

  Register it in resources/larsadmin/js/extension.js:

    import StatsCard from \'./cards/StatsCard.vue\'
    cards: { \'app/stats\': StatsCard },',
            22 => '0 larsadmin:card {"name":"WelcomeCard"} => INFO  Card [WelcomeCard] created with the component key [app/welcome-card].

  Register it in resources/larsadmin/js/extension.js:

    import WelcomeCard from \'./cards/WelcomeCard.vue\'
    cards: { \'app/welcome-card\': WelcomeCard },',
            23 => '0 larsadmin:tool {"name":"Backups"} => INFO  Tool [Backups] created with the component key [app/backups].

  Register it in resources/larsadmin/js/extension.js:

    import BackupsPage from \'./tools/BackupsPage.vue\'
    tools: { \'app/backups\': BackupsPage },

  And in the boot() method of your LarsadminServiceProvider:

    Larsadmin::tools([\\App\\Larsadmin\\Tools\\Backups::class]);',
            24 => '0 larsadmin:tool {"name":"SystemPage"} => INFO  Tool [SystemPage] created with the component key [app/system-page].

  Register it in resources/larsadmin/js/extension.js:

    import SystemPage from \'./tools/SystemPage.vue\'
    tools: { \'app/system-page\': SystemPage },

  And in the boot() method of your LarsadminServiceProvider:

    Larsadmin::tools([\\App\\Larsadmin\\Tools\\SystemPage::class]);',
            25 => '0 larsadmin:extension {"name":"acme/larsadmin-rating"} => INFO  Extension [acme/larsadmin-rating] created in [packages/acme/larsadmin-rating].

  ⇂ composer config repositories.acme-larsadmin-rating path packages/acme/larsadmin-rating
  ⇂ composer require acme/larsadmin-rating:@dev
  ⇂ cd packages/acme/larsadmin-rating && npm install && npm run build',
            26 => '0 larsadmin:extension {"name":"acme.co/stars","--path":"extensions/local","--field":"star-rating","--card":"TopRated"} => INFO  Extension [acme.co/stars] created in [extensions/local/acme.co/stars].

  ⇂ composer config repositories.acme-co-stars path extensions/local/acme.co/stars
  ⇂ composer require acme.co/stars:@dev
  ⇂ cd extensions/local/acme.co/stars && npm install && npm run build',
            27 => '1 larsadmin:extension {"name":"acme/larsadmin-rating"} => ERROR  packages/acme/larsadmin-rating already contains: composer.json, package.json, vite.config.js, .gitignore, README.md, src/RatingServiceProvider.php, src/Fields/ExampleField.php, src/Cards/ExampleCard.php, resources/js/extension.js, resources/js/fields/ExampleFieldInput.vue, resources/js/fields/ExampleFieldDisplay.vue, resources/js/fields/ExampleFieldInput.test.js, resources/js/cards/ExampleCard.vue, resources/css/extension.css, lang/en/ui.php, lang/uk/ui.php, tests/Pest.php, tests/TestCase.php, tests/Fixtures/User.php, tests/Fixtures/TestDashboard.php, tests/FieldTest.php, tests/CardTest.php, tests/HttpTest.php. Use --force to overwrite.',
            28 => '1 larsadmin:extension {"name":"Acme/Rating"} => ERROR  The name [Acme/Rating] is not a valid Composer package name (vendor/package).',
            29 => '1 larsadmin:extension {"name":"acme/x","--path":"../outside"} => ERROR  The --path [../outside] must stay inside the application root.',
            30 => '1 larsadmin:extension {"name":"acme/x","--field":"Same","--card":"Same"} => ERROR  The example field and card need different names.',
            31 => '0 larsadmin:field {"name":"Mood","--extension":"packages/acme/larsadmin-rating"} => INFO  Field [Mood] created with the component key [acme/mood].

  Register it in packages/acme/larsadmin-rating/resources/js/extension.js:

    import MoodInput from \'./fields/MoodInput.vue\'
    import MoodDisplay from \'./fields/MoodDisplay.vue\'
    fields: { \'acme/mood\': { form: MoodInput, display: MoodDisplay } },',
            32 => '0 larsadmin:card {"name":"Top","--extension":"packages/acme/larsadmin-rating","--data":true} => INFO  Card [Top] created with the component key [acme/top].

  Register it in packages/acme/larsadmin-rating/resources/js/extension.js:

    import TopCard from \'./cards/TopCard.vue\'
    cards: { \'acme/top\': TopCard },',
            33 => '0 larsadmin:tool {"name":"Reports","--extension":"packages/acme/larsadmin-rating"} => INFO  Tool [Reports] created with the component key [acme/reports].

  Register it in packages/acme/larsadmin-rating/resources/js/extension.js:

    import ReportsPage from \'./tools/ReportsPage.vue\'
    tools: { \'acme/reports\': ReportsPage },

  And in the boot() method of the package\'s service provider:

    Larsadmin::tools([\\Acme\\LarsadminRating\\Tools\\Reports::class]);',
            34 => '0 larsadmin:tool {"name":"Reports","--extension":"packages/acme/larsadmin-rating","--force":true} => INFO  Tool [Reports] created with the component key [acme/reports].

  Register it in packages/acme/larsadmin-rating/resources/js/extension.js:

    import ReportsPage from \'./tools/ReportsPage.vue\'
    tools: { \'acme/reports\': ReportsPage },

  And in the boot() method of the package\'s service provider:

    Larsadmin::tools([\\Acme\\LarsadminRating\\Tools\\Reports::class]);',
            35 => '1 larsadmin:field {"name":"Mood","--extension":"packages/missing"} => ERROR  No extension package (composer.json with a vendor/name) in [{root}/packages/missing].',
        ],
        'files' => [
            'app/Larsadmin/Actions/DeletePosts.php' => 'b565794d2ec13b95f06339980b57631f6e7d337afdadcc190d6e68acaa18ba1b',
            'app/Larsadmin/Actions/PublishPost.php' => '9015fdda9277e8cc1dbe87b18cfb6bad6acc6517948964d3a6ff3e5d727af6cc',
            'app/Larsadmin/Admin/Comment.php' => 'd36753ff388ec47c2821bd9961dc1921ab09d257f932e491784e990b7118434e',
            'app/Larsadmin/Cards/Sales.php' => '24e9240f9ebf3796730293b12dd6a3ee4273726397c86f7df7072845be2e48b6',
            'app/Larsadmin/Cards/Stats.php' => 'dcc67e82d0ac7da790dcd72edc5fddcea4ac6c62da5bb3922e9ccddd239a590d',
            'app/Larsadmin/Cards/WelcomeCard.php' => 'c22da1ab95aea76b1c692d109a2d4895c1b2843463c303f037feb784edd7ef17',
            'app/Larsadmin/Dashboards/Sales.php' => '2111ffb03cf67dbc7d2f66ccab61f57004df6fed557d253c01c86c1154b972a7',
            'app/Larsadmin/Fields/StarRating.php' => 'abf5c12a1d36f054b5c65224eca8dbef8e2a61fc31c730ba2a965e732dbc57ea',
            'app/Larsadmin/Filters/Region.php' => 'f79687e1c816390ec1b1741d1f1c87200dbd6216922d092c25608005ebf3a2bc',
            'app/Larsadmin/Filters/Status.php' => '68be9cccb6592a22da22bab00ff2c94e166a7dc6705c7ce03e3831e4a42f027d',
            'app/Larsadmin/Member.php' => '74cb667bed2aaecac93a293c559ab2ba19bed5329b7cd779d8a1d46b0cd4f872',
            'app/Larsadmin/Metrics/Goal.php' => 'dfc1ec991fa8cb11a00763228711adcb944176f1ac6b1423c89a640e00ed32d3',
            'app/Larsadmin/Metrics/NewUsers.php' => 'b0fa8a2a34bf0eec98de33282cd58576e30509499efa71b450e08b66115a9ed4',
            'app/Larsadmin/Metrics/Revenue.php' => 'e0654ebf29f27303f6e90dfa1499dcddbdf8b19b6f4b16b8e241a4a9f04c8ac9',
            'app/Larsadmin/Metrics/UsersByRole.php' => '475a097c558c6a45077a8bbab9c22760f54f500e4816659084c4db7e4877c2b4',
            'app/Larsadmin/Metrics/UsersPerDay.php' => '7a17daa61bbed594feb6c761e4bc09c35caa978e9604b8fe232595cd0aba857b',
            'app/Larsadmin/Post.php' => '03547f2a890daacc5e8eaeaf50658b3f1e423729fab243d3ebfd48c3ba10c301',
            'app/Larsadmin/Tools/Backups.php' => '8b75ea3fab4b35ba955a6a0a6c6c26801b0c4f9d1c37042881baef787d1d028a',
            'app/Larsadmin/Tools/SystemPage.php' => 'a675fba4162f5feb69179b8479a8659ef35670e188be43ea9fb38110e85272d5',
            'extensions/local/acme.co/stars/.gitignore' => '39a76f8a9051cc2c0bbbdcfe585a1b702d676d5caab83e1af82ec0616882ed56',
            'extensions/local/acme.co/stars/README.md' => '777c7323b8496f9a023476e3dc97bf3e84cbb99e3ebb96927d8f104d799e45bd',
            'extensions/local/acme.co/stars/composer.json' => 'f5fb41aace633637cf1d3951d5248371c339f13f45ea5a177220cab2b55e0305',
            'extensions/local/acme.co/stars/lang/en/ui.php' => '75ac3a28c40145ae050700ad1944327b8bd84b0c1af48356bb957e8ff81095ae',
            'extensions/local/acme.co/stars/lang/uk/ui.php' => '3e49ed84cc837a24ed596110ff278f14129e7a095bcf30c19f88141d76bdf12b',
            'extensions/local/acme.co/stars/package.json' => '129525a9971e987d22dd023a3a53eb9b0029f1c9f0c5fdba9d7e899e2f863298',
            'extensions/local/acme.co/stars/resources/css/extension.css' => '33083f19182cc6904b32faa55586123e08a97b772daf86f462ce8e91158d65ec',
            'extensions/local/acme.co/stars/resources/js/cards/TopRatedCard.vue' => '75b52660aaf23b1019730052e5ae5d8ff874cd21e37554e7c980adbdfc75d49f',
            'extensions/local/acme.co/stars/resources/js/extension.js' => 'c8df3bd4292187cfd307b31b603cfa87e6abb90775f37a06ba7e033d53119f26',
            'extensions/local/acme.co/stars/resources/js/fields/StarRatingDisplay.vue' => '693bffad84f5b0627550bb10c3771a2a428868b84594d44f6c478db7095fb11b',
            'extensions/local/acme.co/stars/resources/js/fields/StarRatingInput.test.js' => 'e195bfc6819c4291bc2900bb24643244515149f46bc825c46e214637e3e6a2e7',
            'extensions/local/acme.co/stars/resources/js/fields/StarRatingInput.vue' => '547501b1bdb46c7f4a45acaf6fcd940e3becf29212d8fcf2992ca2551627bcae',
            'extensions/local/acme.co/stars/src/Cards/TopRated.php' => '7825ea814e8f72a21dd3ea59885e4ca731b2825f9a271164888a7353eb651614',
            'extensions/local/acme.co/stars/src/Fields/StarRating.php' => 'c2da09361789f0b1f01f6fbde8c95219c68b1aba277e5a8b3429c9c9b2da6abe',
            'extensions/local/acme.co/stars/src/StarsServiceProvider.php' => 'fcf6f8116af3e78d30d6dc6fabba5e995683f1225c1aa228c3fafe004233312b',
            'extensions/local/acme.co/stars/tests/CardTest.php' => '46db35c019e7996943ab5bc67eada4ef0dc13b8906a7b273db4dc71c99ce1225',
            'extensions/local/acme.co/stars/tests/FieldTest.php' => 'a39d29e2c4daa909c29b3c2fb81c90ef0a5ad2880a3439517cbe0af33aac5233',
            'extensions/local/acme.co/stars/tests/Fixtures/TestDashboard.php' => '54568c414df60dc02eedd58f6bc491d49d30929b2e2e91d00a5dfce72194df08',
            'extensions/local/acme.co/stars/tests/Fixtures/User.php' => '0b358300a22d97d8190a39963b898bdaf3cc2410e14abf6ad118b77933a7624b',
            'extensions/local/acme.co/stars/tests/HttpTest.php' => '464064bf492d309de964ed09d26ead7c3903bf6aed945bc08fe7951a5d85ac85',
            'extensions/local/acme.co/stars/tests/Pest.php' => 'ef44c944c34e152751996153b8930abaaa67098206b746282d563b2cf150cf61',
            'extensions/local/acme.co/stars/tests/TestCase.php' => '46aa07d6386f92a4460ebccb35641da2c60db703e91afa3989b0b7f59c108e10',
            'extensions/local/acme.co/stars/vite.config.js' => '0f62e696ecdc471766a49d77d24e6bf5d538b290f098e3e346e861b379a85ae1',
            'packages/acme/larsadmin-rating/.gitignore' => '39a76f8a9051cc2c0bbbdcfe585a1b702d676d5caab83e1af82ec0616882ed56',
            'packages/acme/larsadmin-rating/README.md' => '6d2ea67c99c1d4dbc13b85176ddfe2b2127f5237cca2c7347d9060f7357382d8',
            'packages/acme/larsadmin-rating/composer.json' => '35cd9e7dcce4a3b7beaed1bce4522606f1de9240484db6bb654f73300a907717',
            'packages/acme/larsadmin-rating/lang/en/ui.php' => '0cb77ac4d0e8c08790ad22784c9e91b10f12ae2e8e4aae59d539ebffe0d7aa18',
            'packages/acme/larsadmin-rating/lang/uk/ui.php' => 'bd0f0e12cd36ec26594d88a09994e7f65d77a0d828fe54e383a3e299089d709f',
            'packages/acme/larsadmin-rating/package.json' => 'bf5ffac13ff47eb30e1391c8de10aa596a9732a383b3c8f36f6529964319ad14',
            'packages/acme/larsadmin-rating/resources/css/extension.css' => '33083f19182cc6904b32faa55586123e08a97b772daf86f462ce8e91158d65ec',
            'packages/acme/larsadmin-rating/resources/js/cards/ExampleCard.vue' => 'd3fac4fd7711c7a2a536590feaf3ec1a61dc3c2756b2b66d4b1d8f2551021c64',
            'packages/acme/larsadmin-rating/resources/js/cards/TopCard.vue' => 'd06894f2f5059ad17d3881f9c04375603fa71e5dfb1c1c2dcfdd38c8f5411e5f',
            'packages/acme/larsadmin-rating/resources/js/extension.js' => '6982a73b6a9cc471d20b84e0d60f91c9a505cd4fbced3f0f554dfe92f3e91f21',
            'packages/acme/larsadmin-rating/resources/js/fields/ExampleFieldDisplay.vue' => '461048fd4e42da90944369a11ec64eeb2260d1e5c125310880332113955f3780',
            'packages/acme/larsadmin-rating/resources/js/fields/ExampleFieldInput.test.js' => '08fc5f1b17bd325519c819f11370b795d9a04b1bc517fa2e382a12801de6d1f3',
            'packages/acme/larsadmin-rating/resources/js/fields/ExampleFieldInput.vue' => 'cc45508aca162b1677fbb614a58dad1309b9bb0873f6e54a7ef96a5bc23fec67',
            'packages/acme/larsadmin-rating/resources/js/fields/MoodDisplay.vue' => '9e2bc2b5a7c72b69120ded6979a9b35a3769a2b360cf1559c887d331b01647ac',
            'packages/acme/larsadmin-rating/resources/js/fields/MoodInput.vue' => '428735854713ca96a5e9af20ecc24980edeebf71ffc9e17e46ccb806d21cef77',
            'packages/acme/larsadmin-rating/resources/js/tools/ReportsPage.vue' => '581b572923d3ee632c4364a623e1cf3fa703b66abda331da1fb62e687c37a96b',
            'packages/acme/larsadmin-rating/src/Cards/ExampleCard.php' => 'd10cacfe65b8675e689e53f3c5a07a55c2786ca7309577137aa4d66c0611f2a9',
            'packages/acme/larsadmin-rating/src/Cards/Top.php' => '4e9578c1401ef0f025daa31204745b7dbdaed0919dafc33b756f9ef3d4bd32e0',
            'packages/acme/larsadmin-rating/src/Fields/ExampleField.php' => '5f9ef8478b8ea393bcf8a21d26d2951d01c3e04481ff3280fedba3eb94b32afd',
            'packages/acme/larsadmin-rating/src/Fields/Mood.php' => '41eebc6d14b6e4a4d42717a6fd951ec84232b8557e64252f20d9737532988615',
            'packages/acme/larsadmin-rating/src/RatingServiceProvider.php' => '8a2097c5f929d41fa6770dffa89299d238b64f90df531daba7c3a130bda0c2a1',
            'packages/acme/larsadmin-rating/src/Tools/Reports.php' => 'b0bbbf5292364a08cff6d70f99ecc4014f563ecae1dfedff559bf0c114ca9345',
            'packages/acme/larsadmin-rating/tests/CardTest.php' => 'ddd4d9451b38f065aff692838b22f437e00f644bbfd60e814a26123fef990768',
            'packages/acme/larsadmin-rating/tests/FieldTest.php' => 'cf516257726a49a8c5aa322f147a46af7b65fde9de61df2a26f59a5f19833e4a',
            'packages/acme/larsadmin-rating/tests/Fixtures/TestDashboard.php' => '93aba4deb835fd484cf7b4cb853e814b102300137b6eb8e3e1fd265adccb217e',
            'packages/acme/larsadmin-rating/tests/Fixtures/User.php' => '31cd87ccbd5e6c0b6b7fddb3245459e1222ca5a5275c0f1e825373e61d2f201b',
            'packages/acme/larsadmin-rating/tests/HttpTest.php' => 'a1c326a3898e8dc3e98f00383830f35f5c825e0c877a654b8bbca4cfe38de369',
            'packages/acme/larsadmin-rating/tests/Pest.php' => 'ce38c59e18077e40fd686ce1aa1acaf8475433e423eedea451efd7714e88b1f1',
            'packages/acme/larsadmin-rating/tests/TestCase.php' => 'ffba63b358aba2c64f63994e926f26fea7f6d1faac4be307db3ff3abef9436ce',
            'packages/acme/larsadmin-rating/vite.config.js' => '042ce1fed0eafe4d279f46107c14c1adfbdecc64a181a97937af91e1449fb22e',
            'resources/larsadmin/js/cards/SalesCard.vue' => '172e5b6926e8e138998ece8d4e6f19792d5ebc3a7897ca8cfecba2dfd9a13674',
            'resources/larsadmin/js/cards/StatsCard.vue' => '3228ea540c6a73961109a5bb2d7f77528f06550d8938984542f716b6886fd0b1',
            'resources/larsadmin/js/cards/WelcomeCard.vue' => 'a06cf940ed0a4e38d3d4bab8cc758d9519f202580f9758906ab6914deeefffd5',
            'resources/larsadmin/js/fields/StarRatingDisplay.vue' => '35fb6dc77be22184e9d51960d89fe9433294b0868a03ca1b5674d428dd11348a',
            'resources/larsadmin/js/fields/StarRatingInput.vue' => 'cdc8313109e224f74e42e47a2f9127edcf365b183f2ed37177f82f056e2d5f9e',
            'resources/larsadmin/js/tools/BackupsPage.vue' => '7425d4282ea8b2548b65ecca08472cfc5155eb5a66524187937e117c562110db',
            'resources/larsadmin/js/tools/SystemPage.vue' => 'ed71883a6ffa4c75201102aa8fc726b67afe200e87d787ffb1e51067a5ad5d1e',
        ],
    ];
}
