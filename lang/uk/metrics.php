<?php

return [
    'ranges' => [
        '7' => '7 днів',
        '30' => '30 днів',
        '60' => '60 днів',
        '90' => '90 днів',
        '365' => '365 днів',
        'TODAY' => 'Сьогодні',
        'MTD' => 'З початку місяця',
        'QTD' => 'З початку кварталу',
        'YTD' => 'З початку року',
        'ALL' => 'Увесь час',
    ],
    'other' => 'Інше',
    'none' => 'Без значення',
    'yes' => 'Так',
    'no' => 'Ні',
    // Default trend ranges: the number of buckets of the trend's unit.
    'buckets' => [
        'day' => ':count день|:count дні|:count днів',
        'week' => ':count тиждень|:count тижні|:count тижнів',
        'month' => ':count місяць|:count місяці|:count місяців',
    ],
];
