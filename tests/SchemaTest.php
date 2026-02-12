<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Laravel\Schema\Blueprint;
use MongoDB\Model\IndexInfo;

use function array_map;
use function assert;
use function collect;
use function count;
use function sprintf;

class SchemaTest extends TestCase
{
    private const COLL_1 = 'new_collection';
    private const COLL_2 = 'new_collection_two';
    private const COLL_WITH_COLLATION = 'collection_with_collation';

    public function tearDown(): void
    {
        $database = $this->getConnection('mongodb')->getDatabase();
        assert($database instanceof Database);
        $database->dropCollection(self::COLL_1);
        $database->dropCollection(self::COLL_2);
        $database->dropCollection(self::COLL_WITH_COLLATION);
        $database->dropCollection('test_view');

        parent::tearDown();
    }

    public function testCreate(): void
    {
        Schema::create(self::COLL_1);
        $this->assertTrue(Schema::hasCollection(self::COLL_1));
        $this->assertTrue(Schema::hasTable(self::COLL_1));
    }

    public function testCreateWithCallback(): void
    {
        Schema::create(self::COLL_1, static function ($collection) {
            self::assertInstanceOf(Blueprint::class, $collection);
        });

        $this->assertTrue(Schema::hasCollection(self::COLL_1));
    }

    public function testCreateWithOptions(): void
    {
        Schema::create(self::COLL_2, null, ['capped' => true, 'size' => 1024]);
        $this->assertTrue(Schema::hasCollection(self::COLL_2));
        $this->assertTrue(Schema::hasTable(self::COLL_2));

        $collection = Schema::getCollection(self::COLL_2);
        $this->assertTrue($collection['options']['capped']);
        $this->assertEquals(1024, $collection['options']['size']);
    }

    public function testCreateWithSchemaValidator(): void
    {
        $schema = [
            'bsonType' => 'object',
            'required' => [ 'username' ],
            'properties' => [
                'username' => [
                    'bsonType' => 'string',
                    'description' => 'must be a string and is required',
                ],
            ],
        ];

        Schema::create(self::COLL_2, function (Blueprint $collection) use ($schema) {
            $collection->string('username');
            $collection->jsonSchema(schema: $schema, validationAction: 'warn');
        });

        $this->assertTrue(Schema::hasCollection(self::COLL_2));
        $this->assertTrue(Schema::hasTable(self::COLL_2));

        $collection = Schema::getCollection(self::COLL_2);
        $this->assertEquals(
            ['$jsonSchema' => $schema],
            $collection['options']['validator'],
        );

        $this->assertEquals(
            'warn',
            $collection['options']['validationAction'],
        );
    }

    public function testDrop(): void
    {
        Schema::create(self::COLL_1);
        Schema::drop(self::COLL_1);
        $this->assertFalse(Schema::hasCollection(self::COLL_1));
    }

    public function testBluePrint(): void
    {
        Schema::table(self::COLL_1, static function ($collection) {
            self::assertInstanceOf(Blueprint::class, $collection);
        });

        Schema::table(self::COLL_1, static function ($collection) {
            self::assertInstanceOf(Blueprint::class, $collection);
        });
    }

    public function testIndex(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->index('mykey1');
        });

        $index = $this->assertIndexExists(self::COLL_1, 'mykey1_1');
        $this->assertEquals(1, $index['key']['mykey1']);

        Schema::table(self::COLL_1, function ($collection) {
            $collection->index(['mykey2']);
        });

        $index = $this->assertIndexExists(self::COLL_1, 'mykey2_1');
        $this->assertEquals(1, $index['key']['mykey2']);

        Schema::table(self::COLL_1, function ($collection) {
            $collection->string('mykey3')->index();
        });

        $index = $this->assertIndexExists(self::COLL_1, 'mykey3_1');
        $this->assertEquals(1, $index['key']['mykey3']);
    }

    public function testPrimary(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->string('mykey', 100)->primary();
        });

        $index = $this->assertIndexExists(self::COLL_1, 'mykey_1');
        $this->assertEquals(1, $index['unique']);
    }

    public function testUnique(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->unique('uniquekey');
        });

        $index = $this->assertIndexExists(self::COLL_1, 'uniquekey_1');
        $this->assertEquals(1, $index['unique']);
    }

    public function testDropIndex(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->unique('uniquekey');
            $collection->dropIndex('uniquekey_1');
        });

        $this->assertIndexNotExists(self::COLL_1, 'uniquekey_1');

        Schema::table(self::COLL_1, function ($collection) {
            $collection->unique('uniquekey');
            $collection->dropIndex(['uniquekey']);
        });

        $this->assertIndexNotExists(self::COLL_1, 'uniquekey_1');

        Schema::table(self::COLL_1, function ($collection) {
            $collection->index(['field_a', 'field_b']);
        });

        $this->assertIndexExists(self::COLL_1, 'field_a_1_field_b_1');

        Schema::table(self::COLL_1, function ($collection) {
            $collection->dropIndex(['field_a', 'field_b']);
        });

        $this->assertIndexNotExists(self::COLL_1, 'field_a_1_field_b_1');

        $indexName = 'field_a_-1_field_b_1';
        Schema::table(self::COLL_1, function ($collection) {
            $collection->index(['field_a' => -1, 'field_b' => 1]);
        });

        $this->assertIndexExists(self::COLL_1, $indexName);

        Schema::table(self::COLL_1, function ($collection) {
            $collection->dropIndex(['field_a' => -1, 'field_b' => 1]);
        });

        $this->assertIndexNotExists(self::COLL_1, $indexName);

        $indexName = 'custom_index_name';
        Schema::table(self::COLL_1, function ($collection) use ($indexName) {
            $collection->index(['field_a', 'field_b'], $indexName);
        });

        $this->assertIndexExists(self::COLL_1, $indexName);

        Schema::table(self::COLL_1, function ($collection) use ($indexName) {
            $collection->dropIndex($indexName);
        });

        $this->assertIndexNotExists(self::COLL_1, $indexName);
    }

    public function testDropIndexIfExists(): void
    {
        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->unique('uniquekey');
            $collection->dropIndexIfExists('uniquekey_1');
        });

        $this->assertIndexNotExists(self::COLL_1, 'uniquekey');

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->unique('uniquekey');
            $collection->dropIndexIfExists(['uniquekey']);
        });

        $this->assertIndexNotExists(self::COLL_1, 'uniquekey');

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->index(['field_a', 'field_b']);
        });

        $this->assertIndexExists(self::COLL_1, 'field_a_1_field_b_1');

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropIndexIfExists(['field_a', 'field_b']);
        });

        $this->assertIndexNotExists(self::COLL_1, 'field_a_1_field_b_1');

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->index(['field_a', 'field_b'], 'custom_index_name');
        });

        $this->assertIndexExists(self::COLL_1, 'custom_index_name');

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropIndexIfExists('custom_index_name');
        });

        $this->assertIndexNotExists(self::COLL_1, 'custom_index_name');
    }

    public function testHasIndex(): void
    {
        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->index('myhaskey1');
            $this->assertTrue($collection->hasIndex('myhaskey1_1'));
            $this->assertFalse($collection->hasIndex('myhaskey1'));
        });

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->index('myhaskey2');
            $this->assertTrue($collection->hasIndex(['myhaskey2']));
            $this->assertFalse($collection->hasIndex(['myhaskey2_1']));
        });

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->index(['field_a', 'field_b']);
            $this->assertTrue($collection->hasIndex(['field_a_1_field_b']));
            $this->assertFalse($collection->hasIndex(['field_a_1_field_b_1']));
        });
    }

    public function testSparse(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->sparse('sparsekey');
        });

        $index = $this->assertIndexExists(self::COLL_1, 'sparsekey_1');
        $this->assertEquals(1, $index['sparse']);
    }

    public function testExpire(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->expire('expirekey', 60);
        });

        $index = $this->assertIndexExists(self::COLL_1, 'expirekey_1');
        $this->assertEquals(60, $index['expireAfterSeconds']);
    }

    public function testSoftDeletes(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->softDeletes();
        });

        Schema::table(self::COLL_1, function ($collection) {
            $collection->string('email')->nullable()->index();
        });

        $index = $this->assertIndexExists(self::COLL_1, 'email_1');
        $this->assertEquals(1, $index['key']['email']);
    }

    public function testFluent(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->string('email')->index();
            $collection->string('token')->index();
            $collection->timestamp('created_at');
        });

        $index = $this->assertIndexExists(self::COLL_1, 'email_1');
        $this->assertEquals(1, $index['key']['email']);

        $index = $this->assertIndexExists(self::COLL_1, 'token_1');
        $this->assertEquals(1, $index['key']['token']);
    }

    public function testGeospatial(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->geospatial('point');
            $collection->geospatial('area', '2d');
            $collection->geospatial('continent', '2dsphere');
        });

        $index = $this->assertIndexExists(self::COLL_1, 'point_2d');
        $this->assertEquals('2d', $index['key']['point']);

        $index = $this->assertIndexExists(self::COLL_1, 'area_2d');
        $this->assertEquals('2d', $index['key']['area']);

        $index = $this->assertIndexExists(self::COLL_1, 'continent_2dsphere');
        $this->assertEquals('2dsphere', $index['key']['continent']);
    }

    public function testDummies(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->boolean('activated')->default(0);
            $collection->integer('user_id')->unsigned();
        });
        $this->expectNotToPerformAssertions();
    }

    public function testSparseUnique(): void
    {
        Schema::table(self::COLL_1, function ($collection) {
            $collection->sparse_and_unique('sparseuniquekey');
        });

        $index = $this->assertIndexExists(self::COLL_1, 'sparseuniquekey_1');
        $this->assertEquals(1, $index['sparse']);
        $this->assertEquals(1, $index['unique']);
    }

    public function testRenameColumn(): void
    {
        DB::connection()->table(self::COLL_1)->insert(['test' => 'value']);
        DB::connection()->table(self::COLL_1)->insert(['test' => 'value 2']);
        DB::connection()->table(self::COLL_1)->insert(['column' => 'column value']);

        $check = DB::connection()->table(self::COLL_1)->get();
        $this->assertCount(3, $check);

        $this->assertObjectHasProperty('test', $check[0]);
        $this->assertObjectNotHasProperty('newtest', $check[0]);

        $this->assertObjectHasProperty('test', $check[1]);
        $this->assertObjectNotHasProperty('newtest', $check[1]);

        $this->assertObjectHasProperty('column', $check[2]);
        $this->assertObjectNotHasProperty('test', $check[2]);
        $this->assertObjectNotHasProperty('newtest', $check[2]);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->renameColumn('test', 'newtest');
        });

        $check2 = DB::connection()->table(self::COLL_1)->get();
        $this->assertCount(3, $check2);

        $this->assertObjectHasProperty('newtest', $check2[0]);
        $this->assertObjectNotHasProperty('test', $check2[0]);
        $this->assertSame($check[0]->test, $check2[0]->newtest);

        $this->assertObjectHasProperty('newtest', $check2[1]);
        $this->assertObjectNotHasProperty('test', $check2[1]);
        $this->assertSame($check[1]->test, $check2[1]->newtest);

        $this->assertObjectHasProperty('column', $check2[2]);
        $this->assertObjectNotHasProperty('test', $check2[2]);
        $this->assertObjectNotHasProperty('newtest', $check2[2]);
        $this->assertSame($check[2]->column, $check2[2]->column);
    }

    public function testHasColumn(): void
    {
        $this->assertTrue(Schema::hasColumn(self::COLL_1, '_id'));
        $this->assertTrue(Schema::hasColumn(self::COLL_1, 'id'));

        DB::connection()->table(self::COLL_1)->insert(['column1' => 'value', 'embed' => ['_id' => 1]]);

        $this->assertTrue(Schema::hasColumn(self::COLL_1, 'column1'));
        $this->assertFalse(Schema::hasColumn(self::COLL_1, 'column2'));
        $this->assertTrue(Schema::hasColumn(self::COLL_1, 'embed._id'));
        $this->assertTrue(Schema::hasColumn(self::COLL_1, 'embed.id'));
    }

    public function testHasColumns(): void
    {
        $this->assertTrue(Schema::hasColumns(self::COLL_1, ['_id']));
        $this->assertTrue(Schema::hasColumns(self::COLL_1, ['id']));

        // Insert documents with both column1 and column2
        DB::connection()->table(self::COLL_1)->insert([
            ['column1' => 'value1', 'column2' => 'value2'],
            ['column1' => 'value3'],
        ]);

        $this->assertTrue(Schema::hasColumns(self::COLL_1, ['column1', 'column2']));
        $this->assertFalse(Schema::hasColumns(self::COLL_1, ['column1', 'column3']));
    }

    public function testGetTables()
    {
        $db = DB::connection('mongodb')->getDatabase();
        $db->createCollection(self::COLL_WITH_COLLATION, [
            'collation' => [
                'locale' => 'fr',
                'strength' => 2,
            ],
        ]);

        DB::connection('mongodb')->table(self::COLL_1)->insert(['test' => 'value']);
        DB::connection('mongodb')->table(self::COLL_2)->insert(['test' => 'value']);
        $db->createCollection('test_view', ['viewOn' => self::COLL_1]);
        $dbName = DB::connection('mongodb')->getDatabaseName();

        $tables = Schema::getTables();
        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(2, count($tables));
        $found = false;
        foreach ($tables as $table) {
            $this->assertArrayHasKey('name', $table);
            $this->assertArrayHasKey('size', $table);
            $this->assertArrayHasKey('schema', $table);
            $this->assertArrayHasKey('collation', $table);
            $this->assertArrayHasKey('schema_qualified_name', $table);
            $this->assertNotEquals('test_view', $table['name'], 'Standard views should not be included in the result of getTables.');

            if ($table['name'] === self::COLL_1) {
                $this->assertGreaterThanOrEqual(8192, $table['size']);
                $this->assertEquals($dbName, $table['schema']);
                $this->assertEquals($dbName . '.' . self::COLL_1, $table['schema_qualified_name']);
                $found = true;
            }

            if ($table['name'] === self::COLL_WITH_COLLATION) {
                $this->assertEquals('l=fr;cl=0;cf=off;s=2;no=0;a=non-ignorable;mv=punct;n=0;b=0', $table['collation']);
            }
        }

        if (! $found) {
            $this->fail('Collection "' . self::COLL_1 . '" not found');
        }
    }

    public function testGetViews()
    {
        DB::connection('mongodb')->table(self::COLL_1)->insert(['test' => 'value']);
        DB::connection('mongodb')->table(self::COLL_2)->insert(['test' => 'value']);
        $dbName = DB::connection('mongodb')->getDatabaseName();

        DB::connection('mongodb')->getDatabase()->createCollection('test_view', ['viewOn' => self::COLL_1]);

        $tables = Schema::getViews();

        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(1, count($tables));
        $found = false;
        foreach ($tables as $table) {
            $this->assertArrayHasKey('name', $table);
            $this->assertArrayHasKey('size', $table);
            $this->assertArrayHasKey('schema', $table);
            $this->assertArrayHasKey('schema_qualified_name', $table);

            // Ensure "normal collections" are not in the views list
            $this->assertNotEquals(self::COLL_1, $table['name'], 'Normal collections should not be included in the result of getViews.');

            if ($table['name'] === 'test_view') {
                $this->assertEquals($dbName, $table['schema']);
                $this->assertEquals($dbName . '.test_view', $table['schema_qualified_name']);
                $found = true;
            }
        }

        if (! $found) {
            $this->fail('Collection "test_view" not found');
        }
    }

    public function testGetTableListing()
    {
        DB::connection('mongodb')->table(self::COLL_1)->insert(['test' => 'value']);
        DB::connection('mongodb')->table(self::COLL_2)->insert(['test' => 'value']);

        $tables = Schema::getTableListing();

        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(2, count($tables));
        $this->assertContains(self::COLL_1, $tables);
        $this->assertContains(self::COLL_2, $tables);
    }

    public function testGetTableListingBySchema()
    {
        DB::connection('mongodb')->table(self::COLL_1)->insert(['test' => 'value']);
        DB::connection('mongodb')->table(self::COLL_2)->insert(['test' => 'value']);
        $dbName = DB::connection('mongodb')->getDatabaseName();

        $tables = Schema::getTableListing([$dbName, 'database__that_does_not_exists'], schemaQualified: true);

        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(2, count($tables));
        $this->assertContains($dbName . '.' . self::COLL_1, $tables);
        $this->assertContains($dbName . '.' . self::COLL_2, $tables);

        $tables = Schema::getTableListing([$dbName, 'database__that_does_not_exists'], schemaQualified: false);

        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(2, count($tables));
        $this->assertContains(self::COLL_1, $tables);
        $this->assertContains(self::COLL_2, $tables);
    }

    public function testGetColumns()
    {
        $collection = DB::connection('mongodb')->table(self::COLL_1);
        $collection->insert(['text' => 'value', 'mixed' => ['key' => 'value']]);
        $collection->insert(['date' => new UTCDateTime(), 'binary' => new Binary('binary'), 'mixed' => true]);

        $columns = Schema::getColumns(self::COLL_1);
        $this->assertIsArray($columns);
        $this->assertCount(5, $columns);

        $columns = collect($columns)->keyBy('name');

        $columns->each(function ($column) {
            $this->assertIsString($column['name']);
            $this->assertEquals($column['type'], $column['type_name']);
            $this->assertNull($column['collation']);
            $this->assertIsBool($column['nullable']);
            $this->assertNull($column['default']);
            $this->assertFalse($column['auto_increment']);
            $this->assertIsString($column['comment']);
        });

        $this->assertNull($columns->get('_id'), '_id is renamed to id');
        $this->assertEquals('objectId', $columns->get('id')['type']);
        $this->assertEquals('objectId', $columns->get('id')['generation']['type']);
        $this->assertNull($columns->get('text')['generation']);
        $this->assertEquals('string', $columns->get('text')['type']);
        $this->assertEquals('date', $columns->get('date')['type']);
        $this->assertEquals('binData', $columns->get('binary')['type']);
        $this->assertEquals('bool, object', $columns->get('mixed')['type']);
        $this->assertEquals('2 occurrences', $columns->get('mixed')['comment']);

        // Non-existent collection
        $columns = Schema::getColumns('missing');
        $this->assertSame([], $columns);

        // Qualified table name
        $columns = Schema::getColumns(DB::getDatabaseName() . '.' . self::COLL_1);
        $this->assertIsArray($columns);
        $this->assertCount(5, $columns);
    }

    /** @see AtlasSearchTest::testGetIndexes() */
    public function testGetIndexes()
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->index('mykey1');
            $collection->string('mykey2')->unique('unique_index');
            $collection->string('mykey3')->index();
        });
        $indexes = Schema::getIndexes(self::COLL_1);
        self::assertIsArray($indexes);
        self::assertCount(4, $indexes);

        $expected = [
            [
                'name' => '_id_',
                'columns' => ['_id'],
                'primary' => true,
                'type' => null,
                'unique' => false,
            ],
            [
                'name' => 'mykey1_1',
                'columns' => ['mykey1'],
                'primary' => false,
                'type' => null,
                'unique' => false,
            ],
            [
                'name' => 'unique_index',
                'columns' => ['mykey2'],
                'primary' => false,
                'type' => null,
                'unique' => true,
            ],
            [
                'name' => 'mykey3_1',
                'columns' => ['mykey3'],
                'primary' => false,
                'type' => null,
                'unique' => false,
            ],
        ];

        self::assertSame($expected, $indexes);

        // Non-existent collection
        $indexes = Schema::getIndexes('missing');
        $this->assertSame([], $indexes);
    }

    public function testSearchIndex(): void
    {
        $this->skipIfSearchIndexManagementIsNotSupported();

        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->searchIndex([
                'mappings' => [
                    'dynamic' => false,
                    'fields' => [
                        'foo' => ['type' => 'string', 'analyzer' => 'lucene.whitespace'],
                    ],
                ],
            ]);
        });

        $index = $this->getSearchIndex(self::COLL_1, 'default');
        self::assertNotNull($index);

        self::assertSame('default', $index['name']);
        self::assertSame('search', $index['type']);
        self::assertFalse($index['latestDefinition']['mappings']['dynamic']);
        self::assertSame('lucene.whitespace', $index['latestDefinition']['mappings']['fields']['foo']['analyzer']);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropSearchIndex('default');
        });

        $index = $this->getSearchIndex(self::COLL_1, 'default');
        self::assertNull($index);
    }

    public function testVectorSearchIndex()
    {
        $this->skipIfSearchIndexManagementIsNotSupported();

        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->vectorSearchIndex([
                'fields' => [
                    ['type' => 'vector', 'path' => 'foo', 'numDimensions' => 128, 'similarity' => 'euclidean', 'quantization' => 'none'],
                ],
            ], 'vector');
        });

        $index = $this->getSearchIndex(self::COLL_1, 'vector');
        self::assertNotNull($index);

        self::assertSame('vector', $index['name']);
        self::assertSame('vectorSearch', $index['type']);
        self::assertSame('vector', $index['latestDefinition']['fields'][0]['type']);

        // Drop the index
        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropSearchIndex('vector');
        });

        $index = $this->getSearchIndex(self::COLL_1, 'vector');
        self::assertNull($index);
    }

    public function testStringColumnCreatesJsonSchema(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('username');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('object', $schema['bsonType']);
        $this->assertArrayHasKey('username', $schema['properties']);
        $this->assertSame('string', $schema['properties']['username']['bsonType']);
        $this->assertSame(255, $schema['properties']['username']['maxLength']);
        $this->assertContains('username', $schema['required']);
    }

    public function testStringColumnWithCustomLength(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('name', 100);
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame(100, $schema['properties']['name']['maxLength']);
    }

    public function testNullableStringColumn(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('name')->nullable();
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertArrayHasKey('name', $schema['properties']);
        // nullable should add "null" to bsonType and remove from required
        $this->assertNotContains('name', $schema['required'] ?? []);
    }

    public function testMultipleStringColumns(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('first_name');
            $collection->string('last_name');
            $collection->string('email', 320);
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertCount(3, $schema['properties']);
        $this->assertSame(255, $schema['properties']['first_name']['maxLength']);
        $this->assertSame(255, $schema['properties']['last_name']['maxLength']);
        $this->assertSame(320, $schema['properties']['email']['maxLength']);
        $this->assertContains('first_name', $schema['required']);
        $this->assertContains('last_name', $schema['required']);
        $this->assertContains('email', $schema['required']);
    }

    public function testDropColumnRemovesDataAndSchema(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('name');
            $collection->string('email');
        });

        // Insert test data using MongoDB collection directly
        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne(['name' => 'John', 'email' => 'john@example.com']);

        // Drop a column
        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropColumn('name');
        });

        // Verify schema no longer has the column
        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertArrayNotHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('email', $schema['properties']);
        $this->assertNotContains('name', $schema['required'] ?? []);
        $this->assertContains('email', $schema['required']);

        // Verify data was removed
        $doc = $collection->findOne();
        $this->assertArrayNotHasKey('name', (array) $doc);
        $this->assertArrayHasKey('email', (array) $doc);
    }

    public function testDropMultipleColumns(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('a');
            $collection->string('b');
            $collection->string('c');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne(['a' => '1', 'b' => '2', 'c' => '3']);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropColumn(['a', 'b']);
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertArrayNotHasKey('a', $schema['properties']);
        $this->assertArrayNotHasKey('b', $schema['properties']);
        $this->assertArrayHasKey('c', $schema['properties']);

        $doc = $collection->findOne();
        $this->assertArrayNotHasKey('a', (array) $doc);
        $this->assertArrayNotHasKey('b', (array) $doc);
        $this->assertArrayHasKey('c', (array) $doc);
    }

    public function testDropColumnWithoutSchema(): void
    {
        Schema::create(self::COLL_1);

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne(['name' => 'John', 'age' => 30]);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropColumn('name');
        });

        $doc = $collection->findOne();
        $this->assertArrayNotHasKey('name', (array) $doc);
        $this->assertArrayHasKey('age', (array) $doc);
    }

    public function testAddColumnToExistingCollection(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('name');
        });

        // Insert existing data
        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne(['name' => 'John']);

        // Add a new column via Schema::table
        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->string('email', 320);
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('email', $schema['properties']);
        $this->assertSame(320, $schema['properties']['email']['maxLength']);

        // Existing data should be untouched
        $doc = $collection->findOne();
        $this->assertSame('John', $doc->name);
    }

    public function testSchemaValidationRejectsInvalidData(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('name');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);

        // Inserting a non-string value should fail
        $this->expectException(BulkWriteException::class);
        $collection->insertOne(['name' => 12345]);
    }

    public function testArrayColumnOfStrings(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('tags', 'string');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('array', $schema['properties']['tags']['bsonType']);
        $this->assertSame(['bsonType' => 'string', 'maxLength' => 255], $schema['properties']['tags']['items']);
        $this->assertContains('tags', $schema['required']);
    }

    public function testArrayColumnOfIntegers(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('scores', 'int');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('array', $schema['properties']['scores']['bsonType']);
        // 'int' is not yet mapped, falls back to default 'string'
        $this->assertSame(['bsonType' => 'string'], $schema['properties']['scores']['items']);
    }

    public function testArrayColumnOfObjects(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('metadata', 'object');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('array', $schema['properties']['metadata']['bsonType']);
        $this->assertSame(['bsonType' => 'object'], $schema['properties']['metadata']['items']);
    }

    public function testNullableArrayColumn(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('tags', 'string')->nullable();
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame(['array', 'null'], $schema['properties']['tags']['bsonType']);
        $this->assertNotContains('tags', $schema['required'] ?? []);
    }

    public function testArrayColumnValidatesItemType(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('tags', 'string');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);

        // Valid: array of strings
        $collection->insertOne(['tags' => ['php', 'laravel']]);

        // Invalid: array containing a non-string
        $this->expectException(BulkWriteException::class);
        $collection->insertOne(['tags' => [123, 456]]);
    }

    public function testDropArrayColumn(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('name');
            $collection->array('tags', 'string');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne(['name' => 'John', 'tags' => ['a', 'b']]);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropColumn('tags');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertArrayNotHasKey('tags', $schema['properties']);
        $this->assertArrayHasKey('name', $schema['properties']);

        $doc = $collection->findOne();
        $this->assertArrayNotHasKey('tags', (array) $doc);
    }

    public function testDropNestedObjectProperty(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->object('address');
            $collection->string('address.street');
            $collection->string('address.city');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne(['address' => ['street' => '123 Main St', 'city' => 'NYC']]);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropColumn('address.city');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        // 'city' removed from schema, 'street' remains
        $this->assertArrayNotHasKey('city', $schema['properties']['address']['properties']);
        $this->assertArrayHasKey('street', $schema['properties']['address']['properties']);
        $this->assertNotContains('city', $schema['properties']['address']['required']);
        $this->assertContains('street', $schema['properties']['address']['required']);

        // 'city' removed from document data
        $doc = $collection->findOne();
        $address = (array) $doc->address;
        $this->assertArrayNotHasKey('city', $address);
        $this->assertSame('123 Main St', $address['street']);
    }

    public function testDropArrayWildcardProperty(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('tags', 'object');
            $collection->string('tags.*.name');
            $collection->string('tags.*.value')->nullable();
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);
        $collection->insertOne([
            'tags' => [
                ['name' => 'color', 'value' => 'red'],
                ['name' => 'size', 'value' => 'large'],
            ],
        ]);

        Schema::table(self::COLL_1, function (Blueprint $collection) {
            $collection->dropColumn('tags.*.value');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        // 'value' removed from items schema, 'name' remains
        $items = $schema['properties']['tags']['items'];
        $this->assertArrayNotHasKey('value', $items['properties']);
        $this->assertArrayHasKey('name', $items['properties']);
        $this->assertNotContains('value', $items['required'] ?? []);

        // 'value' removed from all array elements
        $doc = $collection->findOne();
        $tags = array_map(fn ($t) => (array) $t, (array) $doc->tags);
        $this->assertArrayNotHasKey('value', $tags[0]);
        $this->assertArrayNotHasKey('value', $tags[1]);
        $this->assertSame('color', $tags[0]['name']);
        $this->assertSame('size', $tags[1]['name']);
    }

    public function testSetColumn(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->set('roles', ['admin', 'user', 'editor']);
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('array', $schema['properties']['roles']['bsonType']);
        $this->assertSame(
            ['bsonType' => 'string', 'enum' => ['admin', 'user', 'editor']],
            $schema['properties']['roles']['items'],
        );
    }

    public function testObjectColumn(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->object('address');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('object', $schema['properties']['address']['bsonType']);
        $this->assertContains('address', $schema['required']);
    }

    public function testNullableObjectColumn(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->object('meta')->nullable();
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame(['object', 'null'], $schema['properties']['meta']['bsonType']);
        $this->assertNotContains('meta', $schema['required'] ?? []);
    }

    public function testObjectWithNestedProperties(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->object('address');
            $collection->string('address.street');
            $collection->string('address.city')->nullable();
            $collection->string('address.zip', 10);
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        // Root level: address is required
        $this->assertContains('address', $schema['required']);
        $this->assertSame('object', $schema['properties']['address']['bsonType']);

        // Nested properties
        $address = $schema['properties']['address'];
        $this->assertSame('string', $address['properties']['street']['bsonType']);
        $this->assertSame(255, $address['properties']['street']['maxLength']);
        $this->assertSame(['string', 'null'], $address['properties']['city']['bsonType']);
        $this->assertSame(10, $address['properties']['zip']['maxLength']);

        // Nested required: street and zip are required, city is not
        $this->assertContains('street', $address['required']);
        $this->assertContains('zip', $address['required']);
        $this->assertNotContains('city', $address['required']);
    }

    public function testNestedPropertiesWithoutExplicitObject(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('address.street');
            $collection->string('address.city');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        // address should be auto-created as an object
        $this->assertSame('object', $schema['properties']['address']['bsonType']);

        // Nested properties should exist
        $address = $schema['properties']['address'];
        $this->assertSame('string', $address['properties']['street']['bsonType']);
        $this->assertSame('string', $address['properties']['city']['bsonType']);
        $this->assertContains('street', $address['required']);
        $this->assertContains('city', $address['required']);
    }

    public function testDeeplyNestedProperties(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('a.b.c');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $this->assertSame('object', $schema['properties']['a']['bsonType']);
        $this->assertSame('object', $schema['properties']['a']['properties']['b']['bsonType']);
        $this->assertSame('string', $schema['properties']['a']['properties']['b']['properties']['c']['bsonType']);
        $this->assertContains('c', $schema['properties']['a']['properties']['b']['required']);
    }

    public function testNestedPropertyValidation(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->object('address');
            $collection->string('address.city');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);

        // Valid: nested string
        $collection->insertOne(['address' => ['city' => 'Amsterdam']]);

        // Invalid: nested value is not a string
        $this->expectException(BulkWriteException::class);
        $collection->insertOne(['address' => ['city' => 12345]]);
    }

    public function testArrayWildcardDefinesObjectProperties(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('tags', 'object');
            $collection->string('tags.*.name');
            $collection->string('tags.*.value', 100)->nullable();
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        // Root: tags is an array
        $this->assertSame('array', $schema['properties']['tags']['bsonType']);
        $this->assertContains('tags', $schema['required']);

        // Items: object with properties
        $items = $schema['properties']['tags']['items'];
        $this->assertSame('object', $items['bsonType']);
        $this->assertSame('string', $items['properties']['name']['bsonType']);
        $this->assertSame(255, $items['properties']['name']['maxLength']);
        $this->assertSame(['string', 'null'], $items['properties']['value']['bsonType']);
        $this->assertSame(100, $items['properties']['value']['maxLength']);

        // Required scoped to items
        $this->assertContains('name', $items['required']);
        $this->assertNotContains('value', $items['required']);
    }

    public function testArrayWildcardWithoutExplicitArray(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->string('items.*.label');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        // 'items' is auto-created as an object (since we don't know it's an array without explicit declaration)
        $this->assertArrayHasKey('items', $schema['properties']);

        // The wildcard navigates into items.items (the JSON Schema 'items' key)
        $itemsSchema = $schema['properties']['items'];
        $this->assertSame('object', $itemsSchema['items']['bsonType']);
        $this->assertSame('string', $itemsSchema['items']['properties']['label']['bsonType']);
    }

    public function testArrayWildcardValidation(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('tags', 'object');
            $collection->string('tags.*.name');
        });

        $collection = $this->getConnection('mongodb')->getCollection(self::COLL_1);
        assert($collection instanceof Collection);

        // Valid: array of objects with string name
        $collection->insertOne(['tags' => [['name' => 'php'], ['name' => 'laravel']]]);

        // Invalid: name is not a string
        $this->expectException(BulkWriteException::class);
        $collection->insertOne(['tags' => [['name' => 123]]]);
    }

    public function testNestedObjectInsideArrayWildcard(): void
    {
        Schema::create(self::COLL_1, function (Blueprint $collection) {
            $collection->array('people', 'object');
            $collection->object('people.*.address');
            $collection->string('people.*.address.city');
        });

        $collectionInfo = Schema::getCollection(self::COLL_1);
        $schema = $collectionInfo['options']['validator']['$jsonSchema'];

        $items = $schema['properties']['people']['items'];
        $this->assertSame('object', $items['bsonType']);
        $this->assertSame('object', $items['properties']['address']['bsonType']);
        $this->assertSame('string', $items['properties']['address']['properties']['city']['bsonType']);
        $this->assertContains('city', $items['properties']['address']['required']);
    }

    protected function assertIndexExists(string $collection, string $name): IndexInfo
    {
        $index = $this->getIndex($collection, $name);

        self::assertNotNull($index, sprintf('Index "%s.%s" does not exist.', $collection, $name));

        return $index;
    }

    protected function assertIndexNotExists(string $collection, string $name): void
    {
        $index = $this->getIndex($collection, $name);

        self::assertNull($index, sprintf('Index "%s.%s" exists.', $collection, $name));
    }

    protected function getIndex(string $collection, string $name): ?IndexInfo
    {
        $collection = $this->getConnection('mongodb')->getCollection($collection);
        assert($collection instanceof Collection);

        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
                return $index;
            }
        }

        return null;
    }

    protected function getSearchIndex(string $collection, string $name): ?array
    {
        $collection = $this->getConnection('mongodb')->getCollection($collection);
        assert($collection instanceof Collection);

        foreach ($collection->listSearchIndexes(['name' => $name, 'typeMap' => ['root' => 'array', 'array' => 'array', 'document' => 'array']]) as $index) {
            return $index;
        }

        return null;
    }
}
