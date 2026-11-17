<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Test\Unit\Model\Admin;

use PHPUnit\Framework\TestCase;
use Zero\Blog\Model\Admin\Entities;
use Zero\Blog\Model\Post;
use Zero\Blog\Model\ResourceModel\Post as PostResource;
use Zero\Blog\Model\ResourceModel\Post\Collection;

/**
 * The admin entities hand out what di.xml configured for them (no ObjectManager), and know their id field and labels.
 */
class EntitiesTest extends TestCase
{
    public function testFactoriesResourcesAndCollectionsComeFromTheConfiguredArguments(): void
    {
        $post = $this->createStub(Post::class);
        $resource = $this->createStub(PostResource::class);
        $collection = $this->createStub(Collection::class);
        $entities = new Entities(
            [Entities::POST => new class ($post) {
                public function __construct(private readonly object $model)
                {
                }

                public function create(): object
                {
                    return $this->model;
                }
            }],
            [Entities::POST => $resource],
            [Entities::POST => new class ($collection) {
                public function __construct(private readonly object $collection)
                {
                }

                public function create(): object
                {
                    return $this->collection;
                }
            }]
        );

        self::assertSame($post, $entities->create(Entities::POST));
        self::assertSame($resource, $entities->resource(Entities::POST));
        self::assertSame($collection, $entities->collection(Entities::POST));
    }

    public function testIdFieldAndLabels(): void
    {
        $entities = new Entities();

        self::assertSame('category_id', $entities->idField(Entities::CATEGORY));
        self::assertSame('Category', $entities->label(Entities::CATEGORY));
        self::assertSame('Categories', $entities->pluralLabel(Entities::CATEGORY));
        self::assertSame('comment_id', $entities->idField(Entities::COMMENT));
    }

    public function testDiXmlConfiguresEveryEntity(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/di.xml');
        $arguments = $xml->xpath('//type[@name="Zero\Blog\Model\Admin\Entities"]/arguments/argument');
        $configured = [];
        foreach ($arguments as $argument) {
            foreach ($argument->item as $item) {
                $configured[(string) $argument['name']][] = (string) $item['name'];
            }
        }
        $all = [Entities::POST, Entities::CATEGORY, Entities::TAG, Entities::AUTHOR, Entities::COMMENT];

        self::assertSame(['factories' => $all, 'resources' => $all, 'collectionFactories' => $all], $configured);
    }
}
