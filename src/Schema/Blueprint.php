<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use MongoDB\Collection;
use MongoDB\Laravel\Connection;
use Override;

use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function compact;
use function explode;
use function func_get_args;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function iterator_to_array;
use function key;
use function str_replace;

/** @property Connection $connection */
class Blueprint extends BaseBlueprint
{
    // Import $connection property and constructor for Laravel 12 compatibility
    use BlueprintLaravelCompatibility;

    /**
     * The MongoDB collection object for this blueprint.
     *
     * @var Collection
     */
    protected $collection;

    /** @inheritdoc */
    #[Override]
    public function index($columns = null, $name = null, $algorithm = null, $options = [])
    {
        $columns = (array) $columns;

        // Columns are passed as a default array.
        if (is_array($columns) && is_int(key($columns))) {
            // Transform the columns to the required array format.
            $transform = [];

            foreach ($columns as $column) {
                $transform[$column] = 1;
            }

            $columns = $transform;
        }

        if ($name !== null) {
            $options['name'] = $name;
        }

        $this->collection->createIndex($columns, $options);

        return $this;
    }

    /** @inheritdoc */
    #[Override]
    public function primary($columns = null, $name = null, $algorithm = null, $options = [])
    {
        return $this->unique($columns, $name, $algorithm, $options);
    }

    /** @inheritdoc */
    #[Override]
    public function dropIndex($index = null)
    {
        $index = $this->transformColumns($index);

        $this->collection->dropIndex($index);

        return $this;
    }

    /**
     * Indicate that the given index should be dropped, but do not fail if it didn't exist.
     *
     * @param  string|array $indexOrColumns
     *
     * @return Blueprint
     */
    public function dropIndexIfExists($indexOrColumns = null)
    {
        if ($this->hasIndex($indexOrColumns)) {
            $this->dropIndex($indexOrColumns);
        }

        return $this;
    }

    /**
     * Check whether the given index exists.
     *
     * @param  string|array $indexOrColumns
     *
     * @return bool
     */
    public function hasIndex($indexOrColumns = null)
    {
        $indexOrColumns = $this->transformColumns($indexOrColumns);
        foreach ($this->collection->listIndexes() as $index) {
            if (is_array($indexOrColumns) && in_array($index->getName(), $indexOrColumns)) {
                return true;
            }

            if (is_string($indexOrColumns) && $index->getName() === $indexOrColumns) {
                return true;
            }
        }

        return false;
    }

    public function jsonSchema(
        array $schema = [],
        ?string $validationLevel = null,
        ?string $validationAction = null,
    ): void {
        $options = array_merge(
            [
                'validator' => ['$jsonSchema' => $schema],
            ],
            $validationLevel ? ['validationLevel' => $validationLevel] : [],
            $validationAction ? ['validationAction' => $validationAction] : [],
        );

        $this->connection->getDatabase()->modifyCollection($this->collection->getCollectionName(), $options);

        // Explicit jsonSchema() call takes precedence over addColumn() schema generation
        $this->columns = [];
    }

    /**
     * @param  string|array $indexOrColumns
     *
     * @return string
     */
    protected function transformColumns($indexOrColumns)
    {
        if (is_array($indexOrColumns)) {
            // Transform the columns to the index name.
            $transform = [];

            foreach ($indexOrColumns as $key => $value) {
                if (is_int($key)) {
                    // There is no sorting order, use the default.
                    $column  = $value;
                    $sorting = '1';
                } else {
                    // This is a column with sorting order e.g 'my_column' => -1.
                    $column  = $key;
                    $sorting = $value;
                }

                $transform[$column] = $column . '_' . $sorting;
            }

            $indexOrColumns = implode('_', $transform);
        }

        return $indexOrColumns;
    }

    /** @inheritdoc */
    #[Override]
    public function unique($columns = null, $name = null, $algorithm = null, $options = [])
    {
        $options['unique'] = true;

        $this->index($columns, $name, $algorithm, $options);

        return $this;
    }

    /**
     * Specify a sparse index for the collection.
     *
     * @param string|array $columns
     * @param array        $options
     *
     * @return Blueprint
     */
    public function sparse($columns = null, $options = [])
    {
        $options['sparse'] = true;

        $this->index($columns, null, null, $options);

        return $this;
    }

    /**
     * Specify a geospatial index for the collection.
     *
     * @param string|array $columns
     * @param string       $index
     * @param array        $options
     *
     * @return Blueprint
     */
    public function geospatial($columns = null, $index = '2d', $options = [])
    {
        if ($index === '2d' || $index === '2dsphere') {
            $columns = (array) $columns;

            $columns = array_flip($columns);

            foreach ($columns as $column => $value) {
                $columns[$column] = $index;
            }

            $this->index($columns, null, null, $options);
        }

        return $this;
    }

    /**
     * Specify the number of seconds after which a document should be considered expired based,
     * on the given single-field index containing a date.
     *
     * @param string|array $columns
     * @param int          $seconds
     *
     * @return Blueprint
     */
    public function expire($columns, $seconds)
    {
        $this->index($columns, null, null, ['expireAfterSeconds' => $seconds]);

        return $this;
    }

    /**
     * Indicate that the collection needs to be created.
     *
     * @param array $options
     *
     * @return void
     */
    #[Override]
    public function create($options = [])
    {
        $collection = $this->collection->getCollectionName();

        $db = $this->connection->getDatabase();

        // Ensure the collection is created.
        $db->createCollection($collection, $options);
    }

    /** @inheritdoc */
    #[Override]
    public function drop()
    {
        $this->collection->drop();

        return $this;
    }

    public function array(string $column, string $itemType): ColumnDefinition
    {
        return $this->addColumn('array', $column, compact('itemType'));
    }

    /**
     * Create a new object column on the blueprint.
     *
     * @return ColumnDefinition
     */
    public function object(string $column): ColumnDefinition
    {
        return $this->addColumn('object', $column);
    }

    /** @inheritdoc */
    #[Override]
    public function renameColumn($from, $to)
    {
        $this->collection->updateMany([$from => ['$exists' => true]], ['$rename' => [$from => $to]]);

        return $this;
    }

    /**
     * Apply column definitions to the collection's JSON Schema.
     *
     * Called by the Builder after the user callback has finished,
     * so that fluent modifiers like ->nullable() have already been applied.
     */
    public function applyColumnSchemas(): void
    {
        if ($this->columns === []) {
            return;
        }

        // Check if the collection exists to determine if we can apply schema
        $collectionName = $this->collection->getCollectionName();
        $collections = iterator_to_array(
            $this->connection->getDatabase()->listCollections(['filter' => ['name' => $collectionName]]),
            false,
        );
        $collectionExists = $collections !== [];

        if ($collectionExists) {
            $schema = $this->getCurrentJsonSchema();

            foreach ($this->columns as $definition) {
                $name = $definition->name;
                $type = $definition->type;
                $parameters = $definition->getAttributes();

                $property = $this->mapTypeToBsonSchema($type, $parameters);

                // Handle nullable: add "null" to allowed bsonType
                if ($definition->nullable) {
                    $property['bsonType'] = [$property['bsonType'], 'null'];
                }

                // Place the property in the schema, handling dot-notation for nesting
                $segments = explode('.', $name);
                $leaf = array_pop($segments);
                $target = &$schema;

                foreach ($segments as $i => $segment) {
                    if ($segment === '*') {
                        // The current target is an array — fix its bsonType
                        $target['bsonType'] = 'array';
                        unset($target['properties']);

                        // Navigate into the array's items object
                        if (! isset($target['items'])) {
                            $target['items'] = ['bsonType' => 'object'];
                        }

                        $target = &$target['items'];

                        continue;
                    }

                    // Auto-create intermediate if it doesn't exist
                    // Look ahead: if next segment is '*', this is an array
                    $nextIsWildcard = isset($segments[$i + 1]) && $segments[$i + 1] === '*';
                    $defaultType = $nextIsWildcard ? 'array' : 'object';

                    if (! isset($target['properties'][$segment])) {
                        $target['properties'][$segment] = ['bsonType' => $defaultType];
                    }

                    $target = &$target['properties'][$segment];
                }

                $target['properties'][$leaf] = $property;

                // Handle required at the correct nesting level
                $isRequired = ! $definition->change && ! $definition->nullable;
                $removeRequired = $definition->change && $definition->nullable;

                if ($isRequired) {
                    $target['required'] = array_values(array_unique(array_merge($target['required'] ?? [], [$leaf])));
                }

                if ($removeRequired && isset($target['required'])) {
                    $target['required'] = array_values(
                        array_filter($target['required'], fn ($r) => $r !== $leaf),
                    );
                }

                unset($target);
            }

            $this->applyJsonSchema($schema);
        }

        // Handle index modifiers from column definitions (these auto-create the collection)
        $this->addFluentIndexes();

        $this->columns = [];
    }

    /** @inheritdoc */
    #[Override]
    public function dropColumn($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        // Remove from JSON Schema first (before data removal, so the validator
        // won't reject the update that removes required fields)
        $schema = $this->getCurrentJsonSchema();
        $schemaModified = false;

        foreach ($columns as $column) {
            $segments = explode('.', $column);
            $leaf = array_pop($segments);
            $target = &$schema;

            // Navigate to the parent of the property to drop
            foreach ($segments as $segment) {
                if ($segment === '*') {
                    if (! isset($target['items'])) {
                        break 2;
                    }

                    $target = &$target['items'];

                    continue;
                }

                if (! isset($target['properties'][$segment])) {
                    break 2;
                }

                $target = &$target['properties'][$segment];
            }

            if (isset($target['properties'][$leaf])) {
                unset($target['properties'][$leaf]);
                $schemaModified = true;
            }

            if (isset($target['required'])) {
                $target['required'] = array_values(
                    array_filter($target['required'], fn ($r) => $r !== $leaf),
                );
                $schemaModified = true;
            }

            unset($target);
        }

        if ($schemaModified) {
            $this->applyJsonSchema($schema);
        }

        // Remove field data from all documents
        // Replace '*' with '$[]' (all positional operator) for array elements
        $unset = [];
        foreach ($columns as $column) {
            $unset[str_replace('.*.', '.$[].', $column)] = 1;
        }

        $this->collection->updateMany([], ['$unset' => $unset]);

        return $this;
    }

    protected function getCurrentJsonSchema(): array
    {
        $collectionName = $this->collection->getCollectionName();
        $collections = iterator_to_array(
            $this->connection->getDatabase()->listCollections(['filter' => ['name' => $collectionName]]),
            false,
        );

        $validator = isset($collections[0])
            ? ($collections[0]->getOptions()['validator']['$jsonSchema'] ?? [])
            : [];

        return array_merge([
            'bsonType' => 'object',
            'properties' => [],
            'additionalProperties' => true,
        ], $validator);
    }

    protected function applyJsonSchema(array $schema): void
    {
        // Ensure 'properties' keys are objects (not empty arrays) for MongoDB
        $schema = $this->ensurePropertiesAreObjects($schema);

        $this->connection->getDatabase()->modifyCollection(
            $this->collection->getCollectionName(),
            ['validator' => ['$jsonSchema' => $schema]],
        );
    }

    /**
     * Recursively convert empty 'properties' arrays to objects so they
     * serialize as JSON {} instead of [].
     */
    protected function ensurePropertiesAreObjects(array $schema): array
    {
        if (array_key_exists('properties', $schema)) {
            if ($schema['properties'] === []) {
                $schema['properties'] = (object) [];
            } else {
                foreach ($schema['properties'] as $key => $value) {
                    if (is_array($value)) {
                        $schema['properties'][$key] = $this->ensurePropertiesAreObjects($value);
                    }
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->ensurePropertiesAreObjects($schema['items']);
        }

        return $schema;
    }

    protected function mapTypeToBsonSchema(string $type, array $parameters = []): array
    {
        [$bsonType, $defaults] = match ($type) {
            'string'  => ['string', ['maxLength' => $parameters['length'] ?? Builder::$defaultStringLength]],
            'char'    => ['string', ['maxLength' => $parameters['length'] ?? Builder::$defaultStringLength]],
            'array'   => [
                'array', isset($parameters['itemType'])
                ?
            ['items' => $this->mapTypeToBsonSchema($parameters['itemType'])]
                :
            [],
            ],
            'set'     => [
                'array', isset($parameters['allowed'])
                ?
            ['items' => ['bsonType' => 'string', 'enum' => $parameters['allowed']]]
                :
            [],
            ],
            'object'  => ['object', []],
            default   => ['string', []],
        };

        return array_merge(['bsonType' => $bsonType], $defaults);
    }

    /**
     * Specify a sparse and unique index for the collection.
     *
     * @param string|array $columns
     * @param array        $options
     *
     * @return Blueprint
     *
     * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
     */
    public function sparse_and_unique($columns = null, $options = [])
    {
        $options['sparse'] = true;
        $options['unique'] = true;

        $this->index($columns, null, null, $options);

        return $this;
    }

    /**
     * Create an Atlas Search Index.
     *
     * @see https://www.mongodb.com/docs/manual/reference/command/createSearchIndexes/#std-label-search-index-definition-create
     *
     * @phpstan-param array{
     *      analyzer?: string,
     *      analyzers?: list<array>,
     *      searchAnalyzer?: string,
     *      mappings: array{dynamic: true} | array{dynamic?: bool, fields: array<string, array>},
     *      storedSource?: bool|array,
     *      synonyms?: list<array>,
     *      ...
     *  } $definition
     */
    public function searchIndex(array $definition, string $name = 'default'): static
    {
        $this->collection->createSearchIndex($definition, ['name' => $name, 'type' => 'search']);

        return $this;
    }

    /**
     * Create an Atlas Vector Search Index.
     *
     * @see https://www.mongodb.com/docs/manual/reference/command/createSearchIndexes/#std-label-vector-search-index-definition-create
     *
     * @phpstan-param array{fields: array<string, array{type: string, ...}>} $definition
     */
    public function vectorSearchIndex(array $definition, string $name = 'default'): static
    {
        $this->collection->createSearchIndex($definition, ['name' => $name, 'type' => 'vectorSearch']);

        return $this;
    }

    /**
     * Drop an Atlas Search or Vector Search index
     */
    public function dropSearchIndex(string $name): static
    {
        $this->collection->dropSearchIndex($name);

        return $this;
    }

    /**
     * Allows the use of unsupported schema methods.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return Blueprint
     */
    public function __call($method, $parameters)
    {
        // Dummy.
        return $this;
    }
}
