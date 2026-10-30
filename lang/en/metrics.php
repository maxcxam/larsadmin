<?php

return [
    'ranges' => [
        '7' => '7 days',
        '30' => '30 days',
        '60' => '60 days',
        '90' => '90 days',
        '365' => '365 days',
        'TODAY' => 'Today',
        'MTD' => 'Month to date',
        'QTD' => 'Quarter to date',
        'YTD' => 'Year to date',
        'ALL' => 'All time',
    ],
    'other' => 'Other',
    'none' => 'None',
    'yes' => 'Yes',
    'no' => 'No',
    // Default trend ranges: the number of buckets of the trend's unit.
    'buckets' => [
        'day' => ':count day|:count days',
        'week' => ':count week|:count weeks',
        'month' => ':count month|:count months',
    ],
];
