<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Posts and their links: store views, categories, tags, products. A link
 * list is written only when the model carries it (store_ids, category_ids,
 * tag_ids, product_ids), so a save that doesn't touch one keeps it.
 */
class Post extends AbstractDb
{
    /** link data key => [table, column] */
    private const LINKS = [
        'store_ids' => ['zero_blog_post_store', 'store_id'],
        'category_ids' => ['zero_blog_post_category', 'category_id'],
        'tag_ids' => ['zero_blog_post_tag', 'tag_id'],
        'product_ids' => ['zero_blog_post_product', 'product_id'],
    ];

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('zero_blog_post', 'post_id');
    }

    /**
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        if ($object->getId()) {
            foreach ($this->links((int) $object->getId()) as $key => $ids) {
                $object->setData($key, $ids);
                $object->setOrigData($key, $ids);
            }
        }

        return parent::_afterLoad($object);
    }

    /**
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        $connection = $this->getConnection();
        $postId = (int) $object->getId();
        foreach (self::LINKS as $key => [$table, $column]) {
            if (!$object->hasData($key)) {
                continue;
            }
            $ids = array_values(array_unique(array_map('intval', (array) $object->getData($key))));
            $table = $this->getTable($table);
            $connection->delete($table, ['post_id = ?' => $postId]);
            if (!$ids) {
                continue;
            }
            $rows = [];
            foreach ($ids as $position => $id) {
                $row = ['post_id' => $postId, $column => $id];
                if ($key === 'product_ids') {
                    $row['position'] = $position;
                }
                $rows[] = $row;
            }
            $connection->insertMultiple($table, $rows);
        }

        return parent::_afterSave($object);
    }

    /**
     * The post's body (Zero CMS content and its versions) goes with it.
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterDelete(AbstractModel $object)
    {
        foreach (['zero_cms_content', 'zero_cms_revision'] as $table) {
            $this->getConnection()->delete($this->getTable($table), [
                'entity_type = ?' => \Zero\Blog\Model\Post::CONTENT_TYPE,
                'entity_id = ?' => (int) $object->getId(),
            ]);
        }

        return parent::_afterDelete($object);
    }

    /**
     * @return array<string, int[]>
     */
    public function links(int $postId): array
    {
        $connection = $this->getConnection();
        $links = [];
        foreach (self::LINKS as $key => [$table, $column]) {
            $select = $connection->select()->from($this->getTable($table), [$column])->where('post_id = ?', $postId);
            if ($key === 'product_ids') {
                $select->order('position ASC');
            }
            $links[$key] = array_map('intval', $connection->fetchCol($select));
        }

        return $links;
    }

    /**
     * Another post in one of these store views that already uses the URL key
     * (a store view of 0 overlaps every store view), or null.
     *
     * @param int[] $storeIds
     */
    public function urlKeyTakenBy(string $urlKey, array $storeIds, int $exceptPostId): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['p' => $this->getMainTable()], ['post_id'])
            ->join(['s' => $this->getTable('zero_blog_post_store')], 's.post_id = p.post_id', [])
            ->where('p.url_key = ?', $urlKey)
            ->where('p.post_id != ?', $exceptPostId)
            ->limit(1);
        if (!in_array(0, $storeIds, true)) {
            $select->where('s.store_id IN (?)', array_merge([0], $storeIds));
        }
        $id = $connection->fetchOne($select);

        return $id !== false ? (int) $id : null;
    }

    /**
     * Active posts whose publish date falls in (after, until], both UTC.
     *
     * @return int[]
     */
    public function publishedBetween(string $afterUtc, string $untilUtc): array
    {
        $connection = $this->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), ['post_id'])
                ->where('is_active = 1')
                ->where('published_at > ?', $afterUtc)
                ->where('published_at <= ?', $untilUtc)
        ));
    }

    /**
     * The visible post with this URL key in the store view, or null.
     */
    public function findVisibleId(string $urlKey, int $storeId, string $nowUtc): ?int
    {
        $connection = $this->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from(['p' => $this->getMainTable()], ['post_id'])
                ->join(['s' => $this->getTable('zero_blog_post_store')], 's.post_id = p.post_id', [])
                ->where('p.url_key = ?', $urlKey)
                ->where('s.store_id IN (?)', [0, $storeId])
                ->where('p.is_active = 1')
                ->where('p.published_at <= ?', $nowUtc)
                ->order('s.store_id DESC')
                ->limit(1)
        );

        return $id !== false ? (int) $id : null;
    }
}
