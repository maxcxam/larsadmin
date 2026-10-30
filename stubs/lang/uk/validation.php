<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines (uk)
    |--------------------------------------------------------------------------
    |
    | Ukrainian validation messages, copied here by "php artisan larsadmin:install"
    | when the application has no lang/uk/validation.php. Feel free to edit it.
    |
    */

    'accepted' => 'Поле :attribute має бути прийняте.',
    'accepted_if' => 'Поле :attribute має бути прийняте, якщо :other має значення :value.',
    'active_url' => 'Поле :attribute має містити дійсну URL-адресу.',
    'after' => 'Поле :attribute має містити дату після :date.',
    'after_or_equal' => 'Поле :attribute має містити дату не раніше :date.',
    'alpha' => 'Поле :attribute може містити лише літери.',
    'alpha_dash' => 'Поле :attribute може містити лише літери, цифри, дефіси та підкреслення.',
    'alpha_num' => 'Поле :attribute може містити лише літери та цифри.',
    'any_of' => 'Поле :attribute має недійсне значення.',
    'array' => 'Поле :attribute має бути масивом.',
    'array_keys' => 'Поле :attribute може містити лише такі ключі: :values.',
    'ascii' => 'Поле :attribute може містити лише однобайтові літери, цифри та символи.',
    'base64' => 'Поле :attribute має бути дійсним рядком Base64.',
    'before' => 'Поле :attribute має містити дату до :date.',
    'before_or_equal' => 'Поле :attribute має містити дату не пізніше :date.',
    'between' => [
        'array' => 'Поле :attribute має містити від :min до :max елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути від :min до :max кілобайт.',
        'numeric' => 'Поле :attribute має бути між :min і :max.',
        'string' => 'Поле :attribute має містити від :min до :max символів.',
    ],
    'boolean' => 'Поле :attribute має бути істинним або хибним.',
    'can' => 'Поле :attribute містить недозволене значення.',
    'confirmed' => 'Підтвердження поля :attribute не збігається.',
    'contains' => 'У полі :attribute відсутнє обов\'язкове значення.',
    'current_password' => 'Пароль неправильний.',
    'date' => 'Поле :attribute має містити дійсну дату.',
    'date_equals' => 'Поле :attribute має містити дату, що дорівнює :date.',
    'date_format' => 'Поле :attribute має відповідати формату :format.',
    'decimal' => 'Поле :attribute має містити :decimal десяткових знаків.',
    'declined' => 'Поле :attribute має бути відхилене.',
    'declined_if' => 'Поле :attribute має бути відхилене, якщо :other має значення :value.',
    'different' => 'Поля :attribute і :other мають відрізнятися.',
    'digits' => 'Поле :attribute має містити :digits цифр.',
    'digits_between' => 'Поле :attribute має містити від :min до :max цифр.',
    'dimensions' => 'Зображення в полі :attribute має недійсні розміри.',
    'distinct' => 'Поле :attribute містить значення, що повторюється.',
    'doesnt_contain' => 'Поле :attribute не повинно містити жодного з таких значень: :values.',
    'doesnt_end_with' => 'Поле :attribute не повинно закінчуватися жодним із таких значень: :values.',
    'doesnt_start_with' => 'Поле :attribute не повинно починатися жодним із таких значень: :values.',
    'email' => 'Поле :attribute має містити дійсну адресу електронної пошти.',
    'encoding' => 'Поле :attribute має бути в кодуванні :encoding.',
    'ends_with' => 'Поле :attribute має закінчуватися одним із таких значень: :values.',
    'enum' => 'Вибране значення для :attribute недійсне.',
    'exists' => 'Вибране значення для :attribute недійсне.',
    'extensions' => 'Файл у полі :attribute має мати одне з таких розширень: :values.',
    'file' => 'Поле :attribute має бути файлом.',
    'filled' => 'Поле :attribute має містити значення.',
    'gt' => [
        'array' => 'Поле :attribute має містити більше ніж :value елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути більшим за :value кілобайт.',
        'numeric' => 'Поле :attribute має бути більшим за :value.',
        'string' => 'Поле :attribute має містити більше ніж :value символів.',
    ],
    'gte' => [
        'array' => 'Поле :attribute має містити :value елементів або більше.',
        'file' => 'Розмір файлу в полі :attribute має бути не меншим за :value кілобайт.',
        'numeric' => 'Поле :attribute має бути не меншим за :value.',
        'string' => 'Поле :attribute має містити не менше ніж :value символів.',
    ],
    'hex_color' => 'Поле :attribute має містити дійсний шістнадцятковий колір.',
    'image' => 'Поле :attribute має бути зображенням.',
    'in' => 'Вибране значення для :attribute недійсне.',
    'in_array' => 'Поле :attribute має існувати в :other.',
    'in_array_keys' => 'Поле :attribute має містити принаймні один із таких ключів: :values.',
    'integer' => 'Поле :attribute має бути цілим числом.',
    'ip' => 'Поле :attribute має містити дійсну IP-адресу.',
    'ipv4' => 'Поле :attribute має містити дійсну адресу IPv4.',
    'ipv6' => 'Поле :attribute має містити дійсну адресу IPv6.',
    'json' => 'Поле :attribute має бути дійсним рядком JSON.',
    'list' => 'Поле :attribute має бути списком.',
    'lowercase' => 'Поле :attribute має бути записане малими літерами.',
    'lt' => [
        'array' => 'Поле :attribute має містити менше ніж :value елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути меншим за :value кілобайт.',
        'numeric' => 'Поле :attribute має бути меншим за :value.',
        'string' => 'Поле :attribute має містити менше ніж :value символів.',
    ],
    'lte' => [
        'array' => 'Поле :attribute не повинно містити більше ніж :value елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути не більшим за :value кілобайт.',
        'numeric' => 'Поле :attribute має бути не більшим за :value.',
        'string' => 'Поле :attribute має містити не більше ніж :value символів.',
    ],
    'mac_address' => 'Поле :attribute має містити дійсну MAC-адресу.',
    'max' => [
        'array' => 'Поле :attribute не повинно містити більше ніж :max елементів.',
        'file' => 'Розмір файлу в полі :attribute не повинен перевищувати :max кілобайт.',
        'numeric' => 'Поле :attribute не повинно бути більшим за :max.',
        'string' => 'Поле :attribute не повинно перевищувати :max символів.',
    ],
    'max_digits' => 'Поле :attribute не повинно містити більше ніж :max цифр.',
    'mimes' => 'Поле :attribute має бути файлом одного з таких типів: :values.',
    'mimetypes' => 'Поле :attribute має бути файлом одного з таких типів: :values.',
    'min' => [
        'array' => 'Поле :attribute має містити щонайменше :min елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути не меншим за :min кілобайт.',
        'numeric' => 'Поле :attribute має бути не меншим за :min.',
        'string' => 'Поле :attribute має містити щонайменше :min символів.',
    ],
    'min_digits' => 'Поле :attribute має містити щонайменше :min цифр.',
    'missing' => 'Поле :attribute має бути відсутнім.',
    'missing_if' => 'Поле :attribute має бути відсутнім, якщо :other має значення :value.',
    'missing_unless' => 'Поле :attribute має бути відсутнім, якщо :other не має значення :value.',
    'missing_with' => 'Поле :attribute має бути відсутнім, якщо присутнє :values.',
    'missing_with_all' => 'Поле :attribute має бути відсутнім, якщо присутні :values.',
    'multiple_of' => 'Поле :attribute має бути кратним :value.',
    'not_in' => 'Вибране значення для :attribute недійсне.',
    'not_regex' => 'Поле :attribute має недійсний формат.',
    'numeric' => 'Поле :attribute має бути числом.',
    'password' => [
        'letters' => 'Поле :attribute має містити щонайменше одну літеру.',
        'mixed' => 'Поле :attribute має містити щонайменше одну велику та одну малу літеру.',
        'numbers' => 'Поле :attribute має містити щонайменше одну цифру.',
        'symbols' => 'Поле :attribute має містити щонайменше один символ.',
        'uncompromised' => 'Значення :attribute знайдено у списку витоків даних. Оберіть інше значення.',
    ],
    'present' => 'Поле :attribute має бути присутнім.',
    'present_if' => 'Поле :attribute має бути присутнім, якщо :other має значення :value.',
    'present_unless' => 'Поле :attribute має бути присутнім, якщо :other не має значення :value.',
    'present_with' => 'Поле :attribute має бути присутнім, якщо присутнє :values.',
    'present_with_all' => 'Поле :attribute має бути присутнім, якщо присутні :values.',
    'prohibited' => 'Поле :attribute заборонене.',
    'prohibited_if' => 'Поле :attribute заборонене, якщо :other має значення :value.',
    'prohibited_if_accepted' => 'Поле :attribute заборонене, якщо :other прийнято.',
    'prohibited_if_declined' => 'Поле :attribute заборонене, якщо :other відхилено.',
    'prohibited_unless' => 'Поле :attribute заборонене, якщо :other не входить до :values.',
    'prohibits' => 'Поле :attribute забороняє присутність поля :other.',
    'regex' => 'Поле :attribute має недійсний формат.',
    'required' => 'Поле :attribute є обов\'язковим.',
    'required_array_keys' => 'Поле :attribute має містити записи для: :values.',
    'required_if' => 'Поле :attribute є обов\'язковим, якщо :other має значення :value.',
    'required_if_accepted' => 'Поле :attribute є обов\'язковим, якщо :other прийнято.',
    'required_if_declined' => 'Поле :attribute є обов\'язковим, якщо :other відхилено.',
    'required_unless' => 'Поле :attribute є обов\'язковим, якщо :other не входить до :values.',
    'required_with' => 'Поле :attribute є обов\'язковим, якщо присутнє :values.',
    'required_with_all' => 'Поле :attribute є обов\'язковим, якщо присутні :values.',
    'required_without' => 'Поле :attribute є обов\'язковим, якщо :values відсутнє.',
    'required_without_all' => 'Поле :attribute є обов\'язковим, якщо жодне з :values не присутнє.',
    'same' => 'Поле :attribute має збігатися з :other.',
    'size' => [
        'array' => 'Поле :attribute має містити :size елементів.',
        'file' => 'Розмір файлу в полі :attribute має дорівнювати :size кілобайт.',
        'numeric' => 'Поле :attribute має дорівнювати :size.',
        'string' => 'Поле :attribute має містити :size символів.',
    ],
    'starts_with' => 'Поле :attribute має починатися з одного з таких значень: :values.',
    'string' => 'Поле :attribute має бути рядком.',
    'timezone' => 'Поле :attribute має містити дійсний часовий пояс.',
    'unique' => 'Таке значення поля :attribute вже існує.',
    'uploaded' => 'Не вдалося завантажити :attribute.',
    'uppercase' => 'Поле :attribute має бути записане великими літерами.',
    'url' => 'Поле :attribute має містити дійсну URL-адресу.',
    'ulid' => 'Поле :attribute має бути дійсним ULID.',
    'uuid' => 'Поле :attribute має бути дійсним UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [],

];
