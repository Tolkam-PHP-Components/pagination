<?php declare(strict_types=1);

use Tolkam\Pagination\Paginator\DoctrineDbalCursorPaginator;
use Tolkam\Pagination\Paginator\DoctrineSortOrder;

class DoctrineDbalCursorPaginatorTest extends AbstractDoctrineDbalTest
{
    /**
     * @return DoctrineDbalCursorPaginator
     */
    public function makeInstance(): DoctrineDbalCursorPaginator
    {
        return new DoctrineDbalCursorPaginator(
            $this->queryFactory()
        );
    }
    
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
    
    public function testConfigBackupSort()
    {
        $this->expectExceptionMessage('Backup sort is required for cursor pagination');
        
        $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
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
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE);
        
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
        $this->assertNull(
            $result->getCurrentCursor(),
            'should not return current cursor without configured offset'
        );
        $this->assertNotNull(
            $result->getNextCursor(),
            'should return next cursor without configured offset'
        );
    }
    
    /**
     * @depends testResultsWithoutPagination
     */
    public function testFetchMode()
    {
        $paginator = $this->makeInstance();
        
        $paginator->setFetchMode(\Doctrine\DBAL\FetchMode::STANDARD_OBJECT);
        
        $paginator
            ->setPrimarySort(self::COL_UNIQUE)
            ->setBackupSort(self::COL_NON_UNIQUE)
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
     * @depends testResultsWithoutPagination
     */
    public function testOrderOfResults()
    {
        $paginator = $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE)
            ->setMaxResults(3);
        
        $paginator->reverseResults();
        $paginator->paginate();
        
        $expected = [
            [
                'nonUniqueCol' => '4',
                'uniqueCol' => '3',
            ],
            [
                'nonUniqueCol' => '2',
                'uniqueCol' => '2',
            ],
            [
                'nonUniqueCol' => '2',
                'uniqueCol' => '1',
            ],
        ];
        
        $this->assertEquals(
            $expected,
            iterator_to_array($paginator->getItems())
        );
    }
    
    /**
     * @depends testResultsWithoutPagination
     */
    public function testParsingCursor()
    {
        $this->expectNotToPerformAssertions();
        
        $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE)
            ->setAfter('ZGO8BD==')
            ->paginate();
    }
    
    /**
     * @depends testResultsWithoutPagination
     */
    public function testParsingInvalidCursor()
    {
        $this->expectExceptionMessage('Failed to parse cursor');
        
        $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE)
            ->setAfter('invalid')
            ->paginate();
    }
    
    /**
     * @depends testResultsWithoutPagination
     */
    public function testDecodingInvalidCursor()
    {
        $this->expectExceptionMessage('Failed to decode cursor');
        
        $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE)
            ->setAfter('^')
            ->paginate();
    }
    
    /**
     * @dataProvider forwardCursorResults
     */
    public function testForwardCursor(...$args)
    {
        $this->cursorDirection(true, ...$args);
    }
    
    /**
     * @dataProvider backwardCursorResults
     */
    public function testBackwardCursor(...$args)
    {
        $this->cursorDirection(false, ...$args);
    }
    
    /**
     * @depends testForwardCursor
     */
    public function testKeyProcessor()
    {
        $paginator = $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE)
            ->setBackupSort(self::COL_UNIQUE)
            ->encodeCursors(false)
            ->setAfter('4|3')
            ->setMaxResults(1);
        
        $paginator->setKeysProcessor(function (&$primary, &$backup) {
            $primary = '6';
            $backup = '5';
        });
        
        $paginator->paginate();
        
        $expected = [
            'uniqueCol' => '6',
            'nonUniqueCol' => '6',
        ];
        
        $this->assertEquals($expected, $paginator->getItems()->current());
    }
    
    /**
     * @param bool        $isForward
     * @param string|null $cursor
     * @param string|null $expectedPreviousCursor
     * @param string|null $expectedCurrentCursor
     * @param string|null $expectedNextCursor
     * @param int         $expectedCount
     * @param array       $set
     */
    public function cursorDirection(
        bool $isForward,
        ?string $cursor,
        ?string $expectedPreviousCursor,
        ?string $expectedCurrentCursor,
        ?string $expectedNextCursor,
        int $expectedCount,
        array $set
    ) {
        $maxResults = 3;
        
        $sortOrder = DoctrineSortOrder::ORDER_ASC;
        
        $paginator = $this->makeInstance()
            ->setPrimarySort(self::COL_NON_UNIQUE, $sortOrder)
            ->setBackupSort(self::COL_UNIQUE, $sortOrder)
            ->setMaxResults($maxResults)
            ->encodeCursors(false);
        
        if ($isForward) {
            $paginator->setAfter($cursor);
        }
        else {
            $paginator->setBefore($cursor);
        }
        
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
     * @return array[]
     */
    public function forwardCursorResults(): array
    {
        // expected results
        return [
            'firstSet' => [
                'position' => null,
                'expectedPreviousCursor' => null,
                'expectedCurrentCursor' => null,
                'expectedNextCursor' => '4|3',
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
                'position' => '4|3',
                'expectedPreviousCursor' => '4|4',
                'expectedCurrentCursor' => '4|3',
                'expectedNextCursor' => '6|6',
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
                'position' => '6|6',
                'expectedPreviousCursor' => '8|7',
                'expectedCurrentCursor' => '6|6',
                'expectedNextCursor' => '10|9',
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
                'position' => '10|9',
                'expectedPreviousCursor' => '10|10',
                'expectedCurrentCursor' => '10|9',
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
    
    /**
     * @return array[]
     */
    public function backwardCursorResults(): array
    {
        // expected results
        return [
            'firstSet' => [
                'position' => '100|100',
                'expectedPreviousCursor' => '10|9',
                'expectedCurrentCursor' => '100|100',
                'expectedNextCursor' => null,
                'expectedCount' => 3,
                'page' => [
                    [
                        'nonUniqueCol' => '10',
                        'uniqueCol' => '9',
                    ],
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
            'secondSet' => [
                'position' => '10|9',
                'expectedPreviousCursor' => '6|6',
                'expectedCurrentCursor' => '10|9',
                'expectedNextCursor' => '8|8',
                'expectedCount' => 3,
                'page' => [
                    [
                        'nonUniqueCol' => '6',
                        'uniqueCol' => '6',
                    ],
                    [
                        'nonUniqueCol' => '8',
                        'uniqueCol' => '7',
                    ],
                    [
                        'nonUniqueCol' => '8',
                        'uniqueCol' => '8',
                    ],
                ],
            ],
            'thirdSet' => [
                'position' => '6|6',
                'expectedPreviousCursor' => '4|3',
                'expectedCurrentCursor' => '6|6',
                'expectedNextCursor' => '6|5',
                'expectedCount' => 3,
                'page' => [
                    [
                        'nonUniqueCol' => '4',
                        'uniqueCol' => '3',
                    ],
                    [
                        'nonUniqueCol' => '4',
                        'uniqueCol' => '4',
                    ],
                    [
                        'nonUniqueCol' => '6',
                        'uniqueCol' => '5',
                    ],
                ],
            ],
            'fourthSet' => [
                'position' => '4|3',
                'expectedPreviousCursor' => null,
                'expectedCurrentCursor' => '4|3',
                'expectedNextCursor' => '2|2',
                'expectedCount' => 2,
                'page' => [
                    [
                        'nonUniqueCol' => '2',
                        'uniqueCol' => '1',
                    ],
                    [
                        'nonUniqueCol' => '2',
                        'uniqueCol' => '2',
                    ],
                ],
            ],
        ];
    }
}
