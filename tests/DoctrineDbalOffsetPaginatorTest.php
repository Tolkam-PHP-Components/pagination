<?php declare(strict_types=1);

use Tolkam\Pagination\Paginator\DoctrineDbalOffsetPaginator;

class DoctrineDbalOffsetPaginatorTest extends AbstractDoctrineDbalTest
{
    /**
     * @return DoctrineDbalOffsetPaginator
     */
    public function makeInstance(): DoctrineDbalOffsetPaginator
    {
        return new DoctrineDbalOffsetPaginator(
            $this->queryFactory()
        );
    }
    
    /**
     * @depends testDbQuery
     */
    // public function testInstance(QueryBuilder $query): DoctrineDbalOffsetPaginator
    // {
    //     $paginator = new DoctrineDbalOffsetPaginator($query);
    //
    //     $this->assertInstanceOf(DoctrineDbalOffsetPaginator::class, $paginator);
    //
    //     return $paginator;
    // }
    
    public function testConfigPrimarySort()
    {
        $this->expectExceptionMessage('Primary sort must be set first');
        
        $this->makeInstance()->paginate();
    }
    
    public function testConfigPrimarySortEmpty()
    {
        $this->expectExceptionMessage('Primary sort column name can not be empty');
        
        $this->makeInstance()
            ->setPrimarySort('')
            ->paginate();
    }
    
    public function testConfigWrongSortOrder()
    {
        $this->expectExceptionMessage('Unknown sort order');
        
        $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE, 'someOrder')
            ->paginate();
    }
    
    /**
     * @dataProvider tableStructure
     */
    public function testResultsWithoutPagination(
        array $tableStructure
    ) {
        $maxResults = 5;
        $paginator = $this->makeInstance()
            ->setPrimarySort(self::COL_UNIQUE);
        
        // no limit
        $paginator->paginate();
        $this->assertEquals(
            $tableStructure,
            iterator_to_array($paginator->getItems()),
            'should return all rows without optional parameters'
        );
        
        // limit
        $paginator->setMaxResults($maxResults);
        $result = $paginator->paginate();
        
        $expected = array_slice($tableStructure, 0, $maxResults);
        $actual = iterator_to_array($paginator->getItems());
        
        $this->assertEquals(
            $expected,
            $actual,
            'should apply limit correctly and return exact results'
        );
        
        $this->assertEquals(
            $maxResults,
            count($actual),
            'should apply limit correctly and return exact count'
        );
        
        $this->assertEquals(
            $maxResults,
            $result->resultsCount(),
            'should apply limit correctly and return exact results count'
        );
        
        // cursor values
        $this->assertNull(
            $result->getPreviousCursor(),
            'should not return previous cursor without configured offset'
        );
        
        $this->assertEquals(
            1,
            $result->getCurrentCursor(),
            'should return expected current cursor without configured offset'
        );
        
        $this->assertNotNull(
            $result->getNextCursor(),
            'should return next cursor without configured offset'
        );
    }
    
    /**
     * @dataProvider expectedOffsetResults
     */
    public function testOffset(
        ?int $offset,
        ?int $expectedPreviousCursor,
        ?int $expectedCurrentCursor,
        ?int $expectedNextCursor,
        int $expectedCount,
        array $set
    ) {
        $maxResults = 3;
        $paginator = $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE)
            ->setOffset($offset)
            ->setMaxResults($maxResults);
        
        $result = $paginator->paginate();
        $items = iterator_to_array($paginator->getItems());
        
        $this->assertEquals(
            $expectedPreviousCursor,
            $result->getPreviousCursor(),
            'should return correct previous cursor'
        );
        
        $this->assertEquals(
            $expectedCurrentCursor,
            $result->getCurrentCursor(),
            'should return correct current cursor'
        );
        
        $this->assertEquals(
            $expectedNextCursor,
            $result->getNextCursor(),
            'should return correct next cursor'
        );
        
        $this->assertEquals(
            $set,
            $items,
            'should return correct result set'
        );
        
        $this->assertEquals(
            $expectedCount,
            $result->resultsCount(),
            'should return correct result count'
        );
    }
    
    /**
     * @depends testOffset
     */
    public function testFetchMode()
    {
        $paginator = $this->makeInstance()
            ->setPrimarySort(self::COL_UNIQUE);
        
        $paginator->setFetchMode(\Doctrine\DBAL\FetchMode::STANDARD_OBJECT);
        
        $paginator
            ->setOffset(1)
            ->setMaxResults(1)
            ->paginate();
        
        $expected = (object) [
            'uniqueCol' => '1',
            'nonUniqueCol' => '2',
        ];
        
        $this->assertEquals(
            $expected,
            $paginator->getItems()->current()
        );
    }
    
    /**
     * @return array[]
     */
    public function expectedOffsetResults(): array
    {
        return [
            'firstSet' => [
                'offset' => null,
                'expectedPreviousCursor' => null,
                'expectedCurrentCursor' => 1,
                'expectedNextCursor' => 2,
                'expectedCount' => 3,
                'page' => [
                    [
                        'nonUniqueCol' => '2',
                        'uniqueCol' => '1',
                    ],
                    [
                        'nonUniqueCol' => '2',
                        'uniqueCol' => '2',
                    ],
                    [
                        'nonUniqueCol' => '4',
                        'uniqueCol' => '3',
                    ],
                ],
            ],
            'secondSet' => [
                'offset' => 2,
                'expectedPreviousCursor' => 1,
                'expectedCurrentCursor' => 2,
                'expectedNextCursor' => 3,
                'expectedCount' => 3,
                'page' => [
                    [
                        'uniqueCol' => '4',
                        'nonUniqueCol' => '4',
                    ],
                    [
                        'uniqueCol' => '5',
                        'nonUniqueCol' => '6',
                    ],
                    [
                        'uniqueCol' => '6',
                        'nonUniqueCol' => '6',
                    ],
                ],
            ],
            'thirdSet' => [
                'offset' => 3,
                'expectedPreviousCursor' => 2,
                'expectedCurrentCursor' => 3,
                'expectedNextCursor' => 4,
                'expectedCount' => 3,
                'page' => [
                    [
                        'nonUniqueCol' => '8',
                        'uniqueCol' => '7',
                    ],
                    [
                        'nonUniqueCol' => '8',
                        'uniqueCol' => '8',
                    ],
                    [
                        'nonUniqueCol' => '10',
                        'uniqueCol' => '9',
                    ],
                ],
            ],
            'fourthSet' => [
                'offset' => 4,
                'expectedPreviousCursor' => 3,
                'expectedCurrentCursor' => 4,
                'expectedNextCursor' => null,
                'expectedCount' => 2,
                'page' => [
                    [
                        'nonUniqueCol' => '10',
                        'uniqueCol' => '10',
                    ],
                    [
                        'nonUniqueCol' => '12',
                        'uniqueCol' => '11',
                    ],
                ],
            ],
        ];
    }
}
