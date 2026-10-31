<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Test\Unit\Cron;

use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Blog\Cron\PurgeScheduledPosts;
use Zero\Blog\Model\Post;
use Zero\Blog\Model\PostFactory;
use Zero\Blog\Model\ResourceModel\Post as PostResource;

/**
 * A post dated ahead: when its date passes, its page, the lists, its products'
 * blog blocks and the cached 404 of its address are cleaned once.
 */
#[AllowMockObjectsWithoutExpectations]
class PurgeScheduledPostsTest extends TestCase
{
    private const NOW = '2026-10-31 12:00:00';

    /** @var array<int, int[]> post id => linked product ids */
    private array $links = [];
    /** @var array<int, string> store id => web/default/cms_no_route */
    private array $noRoute = [1 => 'no-route'];
    /** @var array<string, int|false> identifier => CMS page id */
    private array $pages = ['no-route' => 2];
    /** @var array<int, string[]> identities of each clean_cache_by_tags, in order */
    private array $dispatched = [];
    /** @var int[] post ids whose model cache was cleaned */
    private array $modelCleaned = [];
    /** @var int[] post ids whose links fail to load */
    private array $failing = [];

    private PostResource&MockObject $postResource;
    private FlagManager&MockObject $flags;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->postResource = $this->createMock(PostResource::class);
        $this->postResource->method('links')->willReturnCallback(function (int $postId) {
            if (in_array($postId, $this->failing, true)) {
                throw new \RuntimeException('links table is gone');
            }
            return ['store_ids' => [0], 'category_ids' => [], 'tag_ids' => [], 'product_ids' => $this->links[$postId] ?? []];
        });
        $this->flags = $this->createMock(FlagManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testPostsThatCameOutSinceTheLastRunAreCleanedWithTheirProducts(): void
    {
        $this->flags->method('getFlagData')->with(PurgeScheduledPosts::FLAG)->willReturn('2026-10-31 11:55:00');
        $this->postResource->expects($this->once())->method('publishedBetween')
            ->with('2026-10-31 11:55:00', self::NOW)->willReturn([7]);
        $this->links[7] = [11, 12];
        $this->flags->expects($this->once())->method('saveFlag')->with(PurgeScheduledPosts::FLAG, self::NOW);

        $this->cron()->execute();

        $this->assertSame([7], $this->modelCleaned);
        $postDispatches = array_values(array_filter($this->dispatched, static fn ($tags) => in_array('zero_blog_post_7', $tags, true)));
        $this->assertCount(1, $postDispatches, 'one clean_cache_by_tags for the post');
        foreach (['zero_blog', 'zero_blog_post_7', 'zero_blog_product_11', 'zero_blog_product_12'] as $tag) {
            $this->assertContains($tag, $postDispatches[0]);
        }
        $this->assertSame([['cms_p_2']], array_values(array_filter($this->dispatched, static fn ($tags) => $tags === ['cms_p_2'])));
    }

    public function testNothingDueCleansNothingButMovesTheMark(): void
    {
        $this->flags->method('getFlagData')->willReturn('2026-10-31 11:55:00');
        $this->postResource->method('publishedBetween')->willReturn([]);
        $this->flags->expects($this->once())->method('saveFlag')->with(PurgeScheduledPosts::FLAG, self::NOW);

        $this->cron()->execute();

        $this->assertSame([], $this->dispatched);
        $this->assertSame([], $this->modelCleaned);
    }

    public function testFirstRunLooksBackOneHour(): void
    {
        $this->flags->method('getFlagData')->willReturn(null);
        $this->postResource->expects($this->once())->method('publishedBetween')
            ->with('2026-10-31 11:00:00', self::NOW)->willReturn([]);
        $this->flags->expects($this->once())->method('saveFlag')->with(PurgeScheduledPosts::FLAG, self::NOW);

        $this->cron()->execute();
    }

    public function testTheNotFoundPageIsCleanedOnceForSeveralPosts(): void
    {
        $this->flags->method('getFlagData')->willReturn('2026-10-31 11:55:00');
        $this->postResource->method('publishedBetween')->willReturn([7, 8, 9]);
        // Stores 1 and 2 share the page; store 3 has its own; store 4 none.
        $this->noRoute = [1 => 'no-route|2', 2 => 'no-route', 3 => 'not-found', 4 => ''];
        $this->pages = ['no-route' => 2, 'not-found' => 5];
        $this->flags->expects($this->once())->method('saveFlag')->with(PurgeScheduledPosts::FLAG, self::NOW);

        $this->cron()->execute();

        $this->assertSame([7, 8, 9], $this->modelCleaned);
        $notFound = array_values(array_filter($this->dispatched, static fn ($tags) => array_filter(
            $tags,
            static fn ($tag) => str_starts_with($tag, 'cms_p_')
        ) !== []));
        $this->assertSame([['cms_p_2', 'cms_p_5']], $notFound, 'one dispatch, each page once');
        $this->assertCount(4, $this->dispatched, 'three posts plus the 404 pages');
    }

    public function testAFailingPostIsLoggedAndTheOthersAreStillCleaned(): void
    {
        $this->flags->method('getFlagData')->willReturn('2026-10-31 11:55:00');
        $this->postResource->method('publishedBetween')->willReturn([7, 8]);
        $this->failing = [7];
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('post 7'), $this->arrayHasKey('exception'));
        $this->flags->expects($this->once())->method('saveFlag')->with(PurgeScheduledPosts::FLAG, self::NOW);

        $this->cron()->execute();

        $this->assertSame([8], $this->modelCleaned);
        $this->assertContains('zero_blog_post_8', $this->dispatched[0]);
        $this->assertSame(['cms_p_2'], $this->dispatched[1]);
    }

    public function testOnlyActivePostsDatedInsideTheWindowAreSelected(): void
    {
        $where = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, &$where) {
            $where[] = [$cond, $value];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['7', '9']);
        $resource = $this->createPartialMock(PostResource::class, ['getConnection', 'getMainTable']);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('zero_blog_post');

        $ids = $resource->publishedBetween('2026-10-31 11:55:00', self::NOW);

        $this->assertSame([7, 9], $ids);
        // is_active = 0 and a NULL date never match these conditions.
        $this->assertSame([
            ['is_active = 1', null],
            ['published_at > ?', '2026-10-31 11:55:00'],
            ['published_at <= ?', self::NOW],
        ], $where);
    }

    public function testTheCronRunsEveryFiveMinutes(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/crontab.xml');
        $job = $xml->xpath('/config/group[@id="default"]/job[@name="zero_blog_purge_scheduled_posts"]')[0] ?? null;

        $this->assertNotNull($job);
        $this->assertSame(PurgeScheduledPosts::class, (string) $job['instance']);
        $this->assertSame('execute', (string) $job['method']);
        $this->assertSame('*/5 * * * *', (string) $job->schedule);
    }

    private function cron(): PurgeScheduledPosts
    {
        $dateTime = $this->createStub(DateTime::class);
        $now = (int) strtotime(self::NOW . ' UTC');
        $dateTime->method('gmtTimestamp')->willReturn($now);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn ($format = null, $input = null) => gmdate((string) $format, $input === null ? $now : (int) $input)
        );

        $postFactory = $this->createStub(PostFactory::class);
        $postFactory->method('create')->willReturnCallback(function () {
            $post = $this->createPartialMock(Post::class, ['cleanModelCache']);
            $post->method('cleanModelCache')->willReturnCallback(function () use ($post) {
                $this->modelCleaned[] = (int) $post->getId();
                return $post;
            });
            return $post;
        });

        $events = $this->createStub(EventManager::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data = []) {
            $this->assertSame('clean_cache_by_tags', $name);
            $this->assertInstanceOf(IdentityInterface::class, $data['object']);
            $this->dispatched[] = $data['object']->getIdentities();
        });

        $stores = [];
        foreach (array_keys($this->noRoute) as $storeId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            fn ($path, $scope = null, $storeId = null) => $path === 'web/default/cms_no_route' ? ($this->noRoute[(int) $storeId] ?? null) : null
        );
        $pages = $this->createStub(PageResource::class);
        $pages->method('checkIdentifier')->willReturnCallback(fn ($identifier) => $this->pages[$identifier] ?? false);

        return new PurgeScheduledPosts(
            $this->postResource,
            $postFactory,
            $this->flags,
            $events,
            $dateTime,
            $storeManager,
            $config,
            $pages,
            $this->logger
        );
    }
}
