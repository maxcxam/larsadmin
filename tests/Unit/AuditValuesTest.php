<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable as SupportStringable;
use Maxcxam\Larsadmin\Audit\AuditValues;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;
use Maxcxam\Larsadmin\Tests\Fixtures\Status;

enum AuditUnitEnum
{
    case Alpha;
}

function auditModel(array $casts = [], array $hidden = []): Model
{
    $model = new class extends Model
    {
        protected $guarded = [];

        public array $testCasts = [];

        public function getCasts(): array
        {
            return $this->testCasts + parent::getCasts();
        }
    };

    $model->testCasts = $casts;
    $model->setHidden($hidden);

    return $model;
}

it('normalizes scalars, enums, dates and objects', function (mixed $value, mixed $expected) {
    expect(AuditValues::normalize($value))->toBe($expected);
})->with([
    'null' => [null, null],
    'bool' => [true, true],
    'int' => [5, 5],
    'float' => [1.5, 1.5],
    'infinite float' => [INF, 'INF'],
    'nan' => [NAN, 'NAN'],
    'string' => ['Київ', 'Київ'],
    'backed enum' => [Status::Live, 'live'],
    'unit enum' => [AuditUnitEnum::Alpha, 'Alpha'],
    'date' => [CarbonImmutable::parse('2026-10-30 14:05:11', 'Europe/Kyiv'), '2026-10-30T14:05:11+02:00'],
    'collection' => [new Collection(['a' => Status::Draft]), ['a' => 'draft']],
    'array object' => [new ArrayObject(['x' => 1]), ['x' => 1]],
    'nested array' => [['list' => [Status::Live, new SupportStringable('s')]], ['list' => ['live', 's']]],
    'json serializable' => [new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return ['k' => 'v'];
        }
    }, ['k' => 'v']],
    'stringable' => [new class implements Stringable
    {
        public function __toString(): string
        {
            return 'text';
        }
    }, 'text'],
    'other object' => [new stdClass, '[object stdClass]'],
]);

it('stops at the maximum depth', function () {
    $value = 'deep';

    foreach (range(1, 20) as $ignored) {
        $value = [$value];
    }

    expect(json_encode(AuditValues::normalize($value)))->not->toContain('deep');
});

it('formats date casts as Y-m-d and datetimes as ISO-8601', function () {
    $model = auditModel(['day' => 'date', 'at' => 'datetime', 'immutable' => 'immutable_date:Y-m-d']);
    $model->setRawAttributes(['day' => '2026-10-30 00:00:00', 'at' => '2026-10-30 14:05:11', 'immutable' => '2026-01-02']);

    expect(AuditValues::attribute($model, 'day'))->toBe('2026-10-30')
        ->and(AuditValues::attribute($model, 'immutable'))->toBe('2026-01-02')
        ->and(AuditValues::attribute($model, 'at'))->toBe('2026-10-30T14:05:11+00:00');
});

it('masks by the four rules', function () {
    $model = auditModel([
        'a' => 'encrypted',
        'b' => 'encrypted:array',
        'c' => AsEncryptedArrayObject::class,
        'd' => AsEncryptedCollection::class.':'.Collection::class,
        'e' => 'string',
    ], ['hidden']);

    expect(AuditValues::isMasked(Password::make('Password'), $model, 'password'))->toBeTrue()
        ->and(AuditValues::isMasked(Password::make('Password')->sensitive(false), $model, 'password'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('Note')->sensitive(), $model, 'note'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('Hidden'), $model, 'hidden'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('A'), $model, 'a'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('B'), $model, 'b'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('C'), $model, 'c'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('D'), $model, 'd'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('E'), $model, 'e'))->toBeFalse()
        ->and(AuditValues::isMasked(Text::make('Plain'), $model, 'plain'))->toBeFalse()
        ->and(Text::make('Note')->sensitive()->sensitive(false)->isSensitive())->toBeFalse();
});

it('fingerprints normalized values', function () {
    expect(AuditValues::fingerprint('x'))->toBe(AuditValues::fingerprint('x'))
        ->and(AuditValues::fingerprint('x'))->not->toBe(AuditValues::fingerprint('y'))
        ->and(AuditValues::fingerprint(1))->not->toBe(AuditValues::fingerprint('1'));
});

it('truncates strings and long arrays to the maximum length', function () {
    config(['larsadmin.audit.max_value_length' => 100]);

    [$string, $cut] = AuditValues::truncate(str_repeat('я', 150));
    [$short, $kept] = AuditValues::truncate('short');
    [$array, $arrayCut] = AuditValues::truncate(array_fill(0, 50, 'abc'));
    [$smallArray, $smallKept] = AuditValues::truncate(['a']);

    expect(mb_strlen($string))->toBe(100)->and($cut)->toBeTrue()
        ->and($short)->toBe('short')->and($kept)->toBeFalse()
        ->and($array)->toBeString()->and(mb_strlen($array))->toBe(100)->and($arrayCut)->toBeTrue()
        ->and($smallArray)->toBe(['a'])->and($smallKept)->toBeFalse();
});

it('never truncates below 100 characters', function () {
    config(['larsadmin.audit.max_value_length' => 5]);

    expect(AuditValues::maxLength())->toBe(100);
});

it('marks truncated sides and leaves masked changes alone', function () {
    config(['larsadmin.audit.max_value_length' => 100]);

    [$changes, $omitted] = AuditValues::limit([
        'body' => ['old' => 'short', 'new' => str_repeat('x', 200)],
        'secret' => ['masked' => true],
    ]);

    expect($changes['body']['new'])->toBe(str_repeat('x', 100))
        ->and($changes['body']['truncated'])->toBe(['new'])
        ->and($changes['body']['old'])->toBe('short')
        ->and($changes['secret'])->toBe(['masked' => true])
        ->and($omitted)->toBeFalse();
});

it('omits the largest values until the changes fit 64 KB', function () {
    config(['larsadmin.audit.max_value_length' => 50000]);

    [$changes, $omitted] = AuditValues::limit([
        'a' => ['old' => str_repeat('a', 40000), 'new' => 'small'],
        'b' => ['old' => null, 'new' => str_repeat('b', 30000)],
        'c' => ['old' => 'c', 'new' => 'd'],
    ]);

    expect($omitted)->toBeTrue()
        ->and($changes['a'])->toBe(['old' => null, 'new' => 'small', 'omitted' => true])
        ->and($changes['b']['new'])->toBe(str_repeat('b', 30000))
        ->and($changes['c'])->toBe(['old' => 'c', 'new' => 'd'])
        ->and(strlen(AuditValues::json($changes)))->toBeLessThanOrEqual(AuditValues::MAX_CHANGES_BYTES);
});

it('stops omitting when only small keys are left', function () {
    $changes = [];

    foreach (range(1, 4000) as $i) {
        $changes['field_with_a_long_attribute_name_'.$i] = ['masked' => true];
    }

    [$limited, $omitted] = AuditValues::limit($changes);

    expect($omitted)->toBeFalse()->and($limited)->toHaveCount(4000);
});

it('masks attributes outside $visible, hashed casts and encryption cast classes (SECA-01)', function () {
    $model = auditModel([
        'pin' => 'hashed',
        'custom' => 'App\Casts\EncryptedString',
        'custom_args' => 'App\Casts\CipherSweetEncrypted:key',
        'plain' => 'App\Casts\Money',
    ]);
    $model->setVisible(['title', 'pin', 'custom', 'custom_args', 'plain']);

    expect(AuditValues::isMasked(Text::make('Title'), $model, 'title'))->toBeFalse()
        ->and(AuditValues::isMasked(Text::make('Token'), $model, 'token'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('Pin'), $model, 'pin'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('Custom'), $model, 'custom'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('Custom args'), $model, 'custom_args'))->toBeTrue()
        ->and(AuditValues::isMasked(Text::make('Plain'), $model, 'plain'))->toBeFalse();
});

it('stores the client name of an uploaded file, never its temporary path (CRA-09)', function () {
    $upload = Uploads::text('report.txt');

    expect(AuditValues::normalize($upload))->toBe('report.txt')
        ->and(AuditValues::normalize(['file' => $upload]))->toBe(['file' => 'report.txt']);
});
