<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Blog\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Blog\Model\ResourceModel\Comment\Grid as CommentGrid;
use Zero\Blog\Model\ResourceModel\Post\Grid as PostGrid;

/**
 * The post and comment grids join a second table; every filter on a column both tables
 * have must be qualified, or MySQL rejects the query ("Column 'url_key' in where clause
 * is ambiguous") and the admin grid shows "Something went wrong".
 */
#[AllowMockObjectsWithoutExpectations]
class GridFilterMapTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../..';

    /**
     * @return array<string, array{class-string, string, string, string, array<string, string>}>
     */
    public static function grids(): array
    {
        return [
            'posts' => [PostGrid::class, 'zero_blog_post', 'zero_blog_author', 'zero_blog_post_listing',
                ['author_name' => 'author.name']],
            'comments' => [CommentGrid::class, 'zero_blog_comment', 'zero_blog_post', 'zero_blog_comment_listing',
                ['post_title' => 'post.title']],
        ];
    }

    /**
     * @param class-string $class
     * @param array<string, string> $joined
     */
    #[DataProvider('grids')]
    public function testSharedColumnsAndListingFiltersAreQualified(
        string $class,
        string $mainTable,
        string $joinedTable,
        string $listing,
        array $joined
    ): void {
        $grid = $this->initGrid($class, $mainTable);
        $mainColumns = $this->tableColumns($mainTable);

        $shared = array_intersect($mainColumns, $this->tableColumns($joinedTable));
        $this->assertNotEmpty($shared);
        foreach ($shared as $column) {
            $this->assertSame('main_table.' . $column, $this->mapped($grid, $column), $column);
        }
        foreach ($this->listingFilters($listing) as $column) {
            $expected = $joined[$column] ?? (in_array($column, $mainColumns, true) ? 'main_table.' . $column : null);
            $this->assertNotNull($expected, "listing filter $column is neither a main nor a joined column");
            $this->assertSame($expected, $this->mapped($grid, $column), $column);
        }
    }

    public function testPostUrlKeyFilterIsQualified(): void
    {
        $grid = $this->initGrid(PostGrid::class, 'zero_blog_post');
        $this->assertSame('main_table.url_key', $this->mapped($grid, 'url_key'));
        $this->assertSame('main_table.created_at', $this->mapped($grid, 'created_at'));
        $this->assertSame('author.name', $this->mapped($grid, 'author_name'));
    }

    public function testCommentPostIdFilterIsQualified(): void
    {
        $grid = $this->initGrid(CommentGrid::class, 'zero_blog_comment');
        $this->assertSame('main_table.post_id', $this->mapped($grid, 'post_id'));
        $this->assertSame('main_table.created_at', $this->mapped($grid, 'created_at'));
        $this->assertSame('post.title', $this->mapped($grid, 'post_title'));
    }

    /**
     * Builds the grid without its ObjectManager-bound constructor and runs _initSelect on a faked select.
     *
     * @param class-string $class
     */
    private function initGrid(string $class, string $mainTable): SearchResult
    {
        $grid = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnArgument(0);
        (new \ReflectionProperty(SearchResult::class, 'resourceConnection'))->setValue($grid, $resource);

        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('from')
            ->with(['main_table' => $mainTable])->willReturnSelf();
        $select->expects($this->once())->method('joinLeft')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        (new \ReflectionProperty($grid, '_select'))->setValue($grid, $select);
        (new \ReflectionProperty($grid, '_mainTable'))->setValue($grid, $mainTable);

        (new \ReflectionMethod($grid, '_initSelect'))->invoke($grid);

        return $grid;
    }

    private function mapped(SearchResult $grid, string $field): string
    {
        return (new \ReflectionMethod($grid, '_getMappedField'))->invoke($grid, $field);
    }

    /**
     * @return string[]
     */
    private function tableColumns(string $table): array
    {
        $schema = simplexml_load_file(self::MODULE . '/etc/db_schema.xml');
        $columns = [];
        foreach ($schema->xpath(sprintf('//table[@name="%s"]/column', $table)) as $column) {
            $columns[] = (string) $column['name'];
        }
        $this->assertNotEmpty($columns, $table);

        return $columns;
    }

    /**
     * @return string[]
     */
    private function listingFilters(string $listing): array
    {
        $xml = simplexml_load_file(self::MODULE . "/view/adminhtml/ui_component/$listing.xml");
        $columns = [];
        foreach ($xml->xpath('//column[settings/filter]') as $column) {
            $columns[] = (string) $column['name'];
        }
        $this->assertNotEmpty($columns, $listing);

        return $columns;
    }
}
