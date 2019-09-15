<?php declare(strict_types=1);

namespace Tolkam\Pagination\Paginator;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Query\QueryBuilder;
use Tolkam\Pagination\PaginationResult;
use Tolkam\Pagination\PaginationResultInterface;
use Tolkam\Pagination\PaginatorInterface;

class DoctrineDbalOffsetPaginator implements PaginatorInterface
{
    /**
     * @var QueryBuilder
     */
    protected QueryBuilder $queryBuilder;
    
    /**
     * @var int
     */
    protected int $currentPage;
    
    /**
     * @var int
     */
    protected int $perPage;
    
    /**
     * @var int
     */
    protected int $itemsCount = 0;
    
    /**
     * @var Statement|null
     */
    protected ?Statement $statement = null;
    
    /**
     * @var array
     */
    private array $fetchMode = [];
    
    /**
     * @param QueryBuilder $queryBuilder
     * @param              $currentPage
     * @param              $perPage
     */
    public function __construct(QueryBuilder $queryBuilder, $currentPage, $perPage)
    {
        $currentPage = intval($currentPage);
        $perPage = intval($perPage);
        
        $this->queryBuilder = $queryBuilder;
        
        $this->currentPage = $currentPage >= 1 ? $currentPage : 1;
        $this->perPage = $perPage >= 1 ? $perPage : self::DEFAULT_PER_PAGE;
    }
    
    /**
     * @inheritDoc
     */
    public function paginate(): PaginationResultInterface
    {
        $extraItemsToFetch = 1;
        $offset = ($this->currentPage - 1) * $this->perPage;
        $limit = $this->perPage + $extraItemsToFetch;
        
        $query = $this->queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        
        $this->statement = $query->execute();
        $fetchedCount = $this->statement->rowCount();
        
        $this->itemsCount = $fetchedCount;
        // set items count to match per page count if has more than per page
        if ($this->itemsCount > $this->perPage) {
            $this->itemsCount = $fetchedCount - $extraItemsToFetch;
        }
        
        $previousPage = $this->currentPage >= 2 ? $this->currentPage - 1 : null;
        $nextPage = $fetchedCount > $this->perPage ? $this->currentPage + 1 : null;
        
        return new PaginationResult($this->itemsCount, $previousPage, $this->currentPage, $nextPage);
    }
    
    /**
     * @inheritDoc
     */
    public function getItems()
    {
        if (!empty($this->fetchMode)) {
            $this->statement->setFetchMode(...$this->fetchMode);
        }
        
        for ($i = 0; $i < $this->itemsCount; $i++) {
            if ($item = $this->statement->fetch()) {
                yield $item;
            }
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
