<?php

namespace Maxcxam\Larsadmin\Fields;

class DateTime extends Date
{
    public string $component = 'datetime-field';

    protected string $inputFormat = 'Y-m-d\TH:i:s';

    protected string $localeFormat = 'datetime';
}
