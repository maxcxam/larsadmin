<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Mcp\Introspection\EntityMetadata;
use Maxcxam\Larsadmin\Mcp\Introspection\IntrospectionTool;
use Maxcxam\Larsadmin\Mcp\Introspection\Metadata;

/**
 * list-entities (design §6.4): the registered entities with their class, file, model, labels and how many
 * fields, actions, filters and cards they define (metadata only, never records).
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class ListEntities extends IntrospectionTool
{
    protected string $description = 'List the registered Larsadmin entities: uriKey, class, file, model, labels, '
        .'navigation group, global search and audit flags, and counts of fields, actions, filters and cards. '
        .'Metadata only, never record data. Use describe-entity for the details of one entity.';

    protected function introspect(Request $request): Response|ResponseFactory
    {
        $synthetic = Metadata::request();

        $entities = array_map(
            fn (string $entity) => EntityMetadata::summary($entity, $synthetic),
            Larsadmin::registeredEntities(),
        );

        return $this->respond(['entities' => $entities], ['entities']);
    }
}
