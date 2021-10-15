<?php declare(strict_types=1);

namespace Tolkam\Pagination\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

abstract class AbstractDoctrineDbalTest extends TestCase
{
    protected const COL_UNIQUE     = 'uniqueCol';
    protected const COL_NON_UNIQUE = 'nonUniqueCol';
    
    /**
     * @return void
     */
    public function testDbQuery()
    {
        $this->assertEquals(
            $this->expectedTableStructure(),
            $this->queryFactory()->execute()->fetchAllAssociative()
        );
    }
    
    /**
     * @return QueryBuilder
     */
    public function queryFactory(): QueryBuilder
    {
        
        $connection = DriverManager::getConnection([
            'url' => 'sqlite:///:memory:',
        ]);
        
        $connection->executeStatement("
            CREATE TABLE test (
                uniqueCol INTEGER PRIMARY KEY,
                nonUniqueCol INTEGER NOT NULL
            )
        ");
        
        foreach (range(1, 11) as $i) {
            $nonUniqueCol = $i + $i % 2;
            
            $connection->executeStatement("
                INSERT INTO test (" . self::COL_NON_UNIQUE . ", " . self::COL_UNIQUE . ")
                VALUES($nonUniqueCol, $i)
            ");
        }
        
        return $connection
            ->createQueryBuilder()
            ->select('*')
            ->from('test');
    }
    
    /**
     * @return array
     */
    public function tableStructure(): array
    {
        return ['structure' => [$this->expectedTableStructure()]];
    }
    
    /**
     * @return string[][]
     */
    protected function expectedTableStructure(): array
    {
        return [
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
            [
                'nonUniqueCol' => '4',
                'uniqueCol' => '4',
            ],
            [
                'nonUniqueCol' => '6',
                'uniqueCol' => '5',
            ],
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
        ];
    }
}
