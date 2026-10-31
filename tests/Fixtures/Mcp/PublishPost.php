<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Textarea;

/**
 * An action with fields whose defaults and options are canaries; handle() must never run.
 */
class PublishPost extends Action
{
    public function fields(Request $request): array
    {
        return [
            Select::make('Channel')
                ->options(McpSpy::spy('action field options', ['web' => McpSpy::CANARY]))
                ->default(McpSpy::spy('action field default'))
                ->rules('required'),
            Textarea::make('Note')->default(McpSpy::CANARY)->help(McpSpy::CANARY)->nullable(),
        ];
    }

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        McpSpy::$calls[] = 'action handle';

        return null;
    }
}
