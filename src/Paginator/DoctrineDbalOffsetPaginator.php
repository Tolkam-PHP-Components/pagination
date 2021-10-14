<?php declare(strict_types=1);

namespace Tolkam\Pagination\Paginator;

use Doctrine\DBAL\Query\QueryBuilder;
use Generator;
use Tolkam\Pagination\PaginationResult;
use Tolkam\Pagination\PaginationResultInterface;
use Tolkam\Pagination\PaginatorInterface;

class DoctrineDbalOffsetPaginator implements PaginatorInterface
{
    use SortingAwareTrait;
    
    /**
     * @var QueryBuilder
     */
    protected QueryBuilder $queryBuilder;
    
    /**
     * @var int
     */
    protected int $offset = 1;
    
    /**
     * @var int
     */
    protected int $maxResults = self::DEFAULT_PER_PAGE;
    
    /**
     * @var array
     */
    protected array $items = [];
    
    /**
     * @var int
     */
    protected int $itemsCount = 0;
    
    /**
     * @var array
     */
    protected array $fetchMode = [];
    
    /**
     * @param QueryBuilder $queryBuilder
     * @param              $currentPage
     * @param              $perPage
     */
    public function __construct(
        QueryBuilder $queryBuilder,
        $currentPage = null,
        $perPage = null
    ) {
        $this->queryBuilder = $queryBuilder;
        
        $this->setOffset(intval($currentPage));
        $this->setMaxResults(intval($perPage));
    }
    
    /**
     * Sets the offset (current page)
     *
     * @param int|null $offset
     *
     * @return self
     */
    public function setOffset(?int $offset): self
    {
        $this->offset = $offset !== null && $offset > 0
            ? $offset
            : $this->offset;
        
        return $this;
    }
    
    /**
     * Sets the max results
     *
     * @param int $maxResults
     *
     * @return self
     */
    public function setMaxResults(int $maxResults): self
    {
        $this->maxResults = $maxResults > 0
            ? $maxResults
            : $this->maxResults;
        
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function paginate(): PaginationResultInterface
    {
        $extraItemsToFetch = 1;
        $offset = ($this->offset - 1) * $this->maxResults;
        $limit = $this->maxResults + $extraItemsToFetch;
        
        $query = $this->queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        
        if ($primarySortKey = $this->getPrimarySortKey()) {
            $query->addOrderBy($primarySortKey, $this->getPrimaryOrder());
        }
        
        if ($backupSortKey = $this->getBackupSortKey()) {
            $query->addOrderBy($backupSortKey, $this->getBackupOrder());
        }
        
        $statement = $query->execute();
        if (!empty($this->fetchMode)) {
            $statement->setFetchMode(...$this->fetchMode);
        }
        $this->items = $statement->fetchAll();
        $fetchedCount = count($this->items);
        
        // set items count to match per page count
        // if it has more than per page
        $this->itemsCount = $fetchedCount;
        if ($this->itemsCount > $this->maxResults) {
            $this->itemsCount = $fetchedCount - $extraItemsToFetch;
        }
        
        $previousPage = $this->offset >= 2 ? $this->offset - 1 : null;
        $nextPage = $fetchedCount > $this->maxResults ? $this->offset + 1 : null;
        
        return new PaginationResult(
            $this->itemsCount,
            $previousPage,
            $this->offset,
            $nextPage
        );
    }
    
    /**
     * @inheritDoc
     */
    public function getItems(): Generator
    {
        for ($i = 0; $i < $this->itemsCount; $i++) {
            yield array_shift($this->items);
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
