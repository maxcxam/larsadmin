<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Maxcxam\Larsadmin\Mcp\Introspection\EntityMetadata;
use Maxcxam\Larsadmin\Mcp\Introspection\IntrospectionTool;
use Maxcxam\Larsadmin\Mcp\Introspection\Metadata;

/**
 * describe-entity (design §6.4): one registered entity's statics, scoping overrides, policy, fields, layout,
 * actions, filters, cards and static warnings, as allow-listed metadata (ADR-038).
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class DescribeEntity extends IntrospectionTool
{
    protected string $description = 'Describe one registered Larsadmin entity by uriKey or class: statics, scoping '
        .'overrides, policy, fields (type, contexts, rules summary, relation), layout, actions, filters, cards and '
        .'warnings. Closures are reported as "closure", never run; option values, defaults and record data are '
        .'never returned. Field "rules" (per create/update) is the complete rule list, implicit type rules first; a '
        .'leading implicit "nullable" there only means empty input is accepted (text fields store it as an empty '
        .'string unless the field\'s own "nullable" flag is true). When the type rules come from host code '
        .'(extension fields, Select, an overridden rulesFor()), "rules" is null and "rulesComputed" false: read '
        .'the field class; "declaredRules" always lists the rules()/creationRules()/updateRules() given in fields(). '
        .'Rule parameters are shown only when numeric or a format.';

    /**
     * @param  JsonSchema  $schema
     * @return array<string, mixed>
     */
    public function schema($schema): array
    {
        return [
            'entity' => $schema->string()
                ->description('The uriKey (e.g. "posts") or the class name of a registered entity.')
                ->required(),
        ];
    }

    protected function introspect(Request $request): Response|ResponseFactory
    {
        $input = $request->get('entity');
        $entity = is_string($input) && $input !== '' && strlen($input) <= 255 ? EntityMetadata::find($input) : null;

        if ($entity === null) {
            $keys = EntityMetadata::keys();

            return Response::error('Unknown entity: pass the uriKey or class of a registered entity. Registered: '
                .($keys === [] ? 'none' : implode(', ', $keys)).'.');
        }

        return $this->respond(
            EntityMetadata::describe($entity, Metadata::request()),
            ['fields', 'actions', 'filters', 'cards'],
        );
    }
}
