<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Note as NoteModel;

class Note extends Entity
{
    public static string $model = NoteModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required'),
        ];
    }
}
