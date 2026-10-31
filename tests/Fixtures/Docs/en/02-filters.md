# 02. Filters

Filters narrow the rows of the list.

## Custom filter

Generate a custom filter with `php artisan larsadmin:filter StatusFilter`. The `larsadmin:filter` command
writes the filter class; register the filter in `filters()` of the Entity.

```php
# This line is a comment in a code block, not a heading
public function filters(): array
{
    return [StatusFilter::make()];
}
```

### Default values

A filter can start with a default value.

### Hidden filters

A hidden filter is applied but not shown.

## Value safety

Values are validated before they reach the query.
