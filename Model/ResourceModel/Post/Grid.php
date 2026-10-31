<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Model\ResourceModel\Post;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * The admin post grid: posts with their author's name and comment counts.
 */
class Grid extends SearchResult
{
    /**
     * Post columns the grid can filter on, qualified so a joined table cannot make them ambiguous.
     */
    public const POST_COLUMNS = [
        'post_id', 'title', 'url_key', 'author_id', 'is_active', 'published_at', 'image',
        'meta_title', 'meta_description', 'created_at', 'updated_at',
    ];

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'zero_blog_post',
        $resourceModel = \Zero\Blog\Model\ResourceModel\Post::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    /**
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()
            ->joinLeft(['author' => $this->getTable('zero_blog_author')], 'author.author_id = main_table.author_id', ['author_name' => 'author.name'])
            ->columns(['pending_comments' => new \Zend_Db_Expr(sprintf(
                '(SELECT COUNT(*) FROM %s c WHERE c.post_id = main_table.post_id AND c.status = 0)',
                $this->getTable('zero_blog_comment')
            ))]);
        // zero_blog_author shares url_key, is_active, image, meta_*, created_at and
        // updated_at with the post table: unqualified, a filter on them is ambiguous.
        foreach (self::POST_COLUMNS as $column) {
            $this->addFilterToMap($column, 'main_table.' . $column);
        }
        $this->addFilterToMap('author_name', 'author.name');

        return $this;
    }
}
