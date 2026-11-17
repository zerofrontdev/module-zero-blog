<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Model\Admin;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * The blog's admin entities (posts, categories, tags, authors, comments):
 * their id field and labels, and a model factory, a resource model and a
 * collection factory for each (configured in di.xml). Shared by the generic
 * admin actions in Controller\Adminhtml\AbstractEntity.
 */
class Entities
{
    public const POST = 'post';
    public const CATEGORY = 'category';
    public const TAG = 'tag';
    public const AUTHOR = 'author';
    public const COMMENT = 'comment';

    private const MAP = [
        self::POST => ['post_id', 'Post', 'Posts'],
        self::CATEGORY => ['category_id', 'Category', 'Categories'],
        self::TAG => ['tag_id', 'Tag', 'Tags'],
        self::AUTHOR => ['author_id', 'Author', 'Authors'],
        self::COMMENT => ['comment_id', 'Comment', 'Comments'],
    ];

    /**
     * @param object[] $factories Entity => the model's generated factory
     * @param AbstractDb[] $resources Entity => its resource model
     * @param object[] $collectionFactories Entity => its collection's generated factory
     */
    public function __construct(
        private readonly array $factories = [],
        private readonly array $resources = [],
        private readonly array $collectionFactories = []
    ) {
    }

    public function create(string $entity): AbstractModel
    {
        return $this->factories[$entity]->create();
    }

    public function resource(string $entity): AbstractDb
    {
        return $this->resources[$entity];
    }

    public function collection(string $entity): AbstractCollection
    {
        return $this->collectionFactories[$entity]->create();
    }

    public function idField(string $entity): string
    {
        return self::MAP[$entity][0];
    }

    public function label(string $entity): string
    {
        return self::MAP[$entity][1];
    }

    public function pluralLabel(string $entity): string
    {
        return self::MAP[$entity][2];
    }

    /**
     * Loads one, or returns a new empty model for id 0.
     */
    public function load(string $entity, int $id): AbstractModel
    {
        $model = $this->create($entity);
        if ($id) {
            $this->resource($entity)->load($model, $id);
        }

        return $model;
    }
}
