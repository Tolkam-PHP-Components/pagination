<?php declare(strict_types=1);

namespace Tolkam\Pagination\Paginator;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Query\QueryBuilder;
use Tolkam\Pagination\PaginationResult;
use Tolkam\Pagination\PaginationResultInterface;
use Tolkam\Pagination\PaginatorInterface;

class DoctrineDbalNullPaginator implements PaginatorInterface
{
    /**
     * @var QueryBuilder
     */
    protected QueryBuilder $queryBuilder;
    
    /**
     * @var Statement|null
     */
    protected ?Statement $statement = null;
    
    /**
     * @var array
     */
    protected array $fetchMode = [];
    
    /**
     * @param QueryBuilder $queryBuilder
     */
    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }
    
    /**
     * @inheritDoc
     */
    public function paginate(): PaginationResultInterface
    {
        $this->statement = $this->queryBuilder->execute();
        
        return new PaginationResult(0, null, null, null);
    }
    
    /**
     * @inheritDoc
     */
    public function getItems()
    {
        if (!empty($this->fetchMode)) {
            $this->statement->setFetchMode(...$this->fetchMode);
        }
        
        while ($item = $this->statement->fetch()) {
            yield $item;
        }
    }
    
    /**
     * Sets statement fetch mode arguments
     *
     * @param mixed ...$args
     */
    public function setFetchMode(...$args)
    {
        $this->fetchMode = $args;
    }
}
