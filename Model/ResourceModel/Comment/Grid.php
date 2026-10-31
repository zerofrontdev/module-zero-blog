<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Model\ResourceModel\Comment;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * The admin comment grid: comments with their post's title.
 */
class Grid extends SearchResult
{
    /**
     * Comment columns the grid can filter on, qualified so the joined post table cannot make them ambiguous.
     */
    public const COMMENT_COLUMNS = [
        'comment_id', 'post_id', 'store_id', 'customer_id', 'author_name', 'author_email',
        'content', 'status', 'created_at',
    ];

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'zero_blog_comment',
        $resourceModel = \Zero\Blog\Model\ResourceModel\Comment::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    /**
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->joinLeft(
            ['post' => $this->getTable('zero_blog_post')],
            'post.post_id = main_table.post_id',
            ['post_title' => 'post.title']
        );
        // zero_blog_post shares post_id and created_at with the comment table.
        foreach (self::COMMENT_COLUMNS as $column) {
            $this->addFilterToMap($column, 'main_table.' . $column);
        }
        $this->addFilterToMap('post_title', 'post.title');

        return $this;
    }
}
