<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Cron;

use Magento\Cms\Model\Page as CmsPage;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zero\Blog\Model\PostFactory;
use Zero\Blog\Model\ResourceModel\Post as PostResource;

/**
 * A post dated ahead becomes visible when its publish date passes, but the
 * blog lists, the post's products' "From the blog" blocks and a cached 404 of
 * its address would stay as they are until their cache expired. Every five
 * minutes this cleans them for the posts that came out since the last run.
 * Best effort: a failure is logged and the mark still moves on (the cache
 * lifetime is the backstop).
 */
class PurgeScheduledPosts
{
    public const FLAG = 'zero_blog_publish_purge';
    /** How far back the first run looks. */
    private const FIRST_RUN_LOOKBACK = 3600;

    public function __construct(
        private readonly PostResource $postResource,
        private readonly PostFactory $postFactory,
        private readonly FlagManager $flags,
        private readonly EventManager $eventManager,
        private readonly DateTime $dateTime,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $config,
        private readonly PageResource $pageResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $now = (string) $this->dateTime->gmtDate('Y-m-d H:i:s');
        $last = (string) $this->flags->getFlagData(self::FLAG);
        if ($last === '') {
            $last = (string) $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - self::FIRST_RUN_LOOKBACK);
        }

        $postIds = $this->postResource->publishedBetween($last, $now);
        foreach ($postIds as $postId) {
            try {
                $post = $this->postFactory->create()->setId($postId);
                $post->setData('product_ids', $this->postResource->links($postId)['product_ids'] ?? []);
                $post->cleanModelCache();
                $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $post]);
            } catch (\Throwable $e) {
                $this->logger->error('Zero_Blog: could not clean the cache of post ' . $postId, ['exception' => $e]);
            }
        }

        if ($postIds) {
            try {
                $this->cleanNotFoundPages();
            } catch (\Throwable $e) {
                $this->logger->error('Zero_Blog: could not clean the cached 404 pages', ['exception' => $e]);
            }
        }

        $this->flags->saveFlag(self::FLAG, $now);
    }

    /**
     * The post's address answered 404 before it came out, and that 404 (the
     * CMS no-route page) may be cached: clean each store view's one once.
     */
    private function cleanNotFoundPages(): void
    {
        $tags = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $value = (string) $this->config->getValue('web/default/cms_no_route', ScopeInterface::SCOPE_STORE, $storeId);
            $identifier = explode('|', $value)[0];
            if ($identifier === '') {
                continue;
            }
            $pageId = $this->pageResource->checkIdentifier($identifier, $storeId);
            if ($pageId) {
                $tags[CmsPage::CACHE_TAG . '_' . $pageId] = true;
            }
        }
        if (!$tags) {
            return;
        }

        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => new class (array_keys($tags)) implements IdentityInterface {
            public function __construct(private readonly array $tags)
            {
            }

            public function getIdentities(): array
            {
                return $this->tags;
            }
        }]);
    }
}
