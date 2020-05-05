<?php declare(strict_types=1);

namespace Tolkam\Pagination\Paginator;

use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Tolkam\Pagination\PaginationResult;
use Tolkam\Pagination\PaginationResultInterface;
use Tolkam\Pagination\PaginatorInterface;

class DoctrineDbalCursorPaginator implements PaginatorInterface
{
    public const ORDER_ASC  = 'ASC';
    public const ORDER_DESC = 'DESC';
    
    protected const CURSOR_GLUE = '|';
    
    /**
     * @var bool
     */
    private static bool $encodeCursors = false;
    
    /**
     * @var QueryBuilder
     */
    protected QueryBuilder $queryBuilder;
    
    /**
     * @var string
     */
    protected ?string $currentCursor = null;
    
    /**
     * @var string|null
     */
    protected ?string $nextCursor = null;
    
    /**
     * @var string|null
     */
    protected ?string $previousCursor = null;
    
    /**
     * @var array|null
     */
    protected ?array $primaryKey = null;
    
    /**
     * @var array|null
     */
    protected ?array $backupKey = null;
    
    /**
     * @var callable|null
     */
    protected $keysProcessor = null;
    
    /**
     * @var int
     */
    protected int $maxResults = self::DEFAULT_PER_PAGE;
    
    /**
     * @var array
     */
    protected array $items = [];
    
    /**
     * @var array
     */
    protected array $fetchMode = [];
    
    /**
     * @param QueryBuilder $queryBuilder
     * @param string       $currentCursor
     */
    public function __construct(QueryBuilder $queryBuilder, ?string $currentCursor)
    {
        $this->queryBuilder = $queryBuilder;
        $this->currentCursor = $currentCursor;
    }
    
    /**
     * Sets the primaryKey
     *
     * @param string $name
     * @param string $order
     *
     * @return self
     */
    public function setPrimaryKey(string $name, string $order = self::ORDER_ASC): self
    {
        $this->validateOrder($order);
        $this->primaryKey = [$name, $order];
        
        return $this;
    }
    
    /**
     * Sets the backupKey
     *
     * @param string $name
     * @param string $order
     *
     * @return self
     */
    public function setBackupKey(string $name, string $order = self::ORDER_ASC): self
    {
        $this->validateOrder($order);
        $this->backupKey = [$name, $order];
        
        return $this;
    }
    
    /**
     * Sets the keysProcessor
     *
     * @param callable|null $keysProcessor
     *
     * @return self
     */
    public function setKeysProcessor(callable $keysProcessor): self
    {
        $this->keysProcessor = $keysProcessor;
        
        return $this;
    }
    
    /**
     * Sets the next cursor
     *
     * @param string|null $nextCursor
     *
     * @return self
     */
    public function setNextCursor(?string $nextCursor): self
    {
        $this->nextCursor = $nextCursor;
        
        return $this;
    }
    
    /**
     * Sets the previous cursor
     *
     * @param string|null $previousCursor
     *
     * @return self
     */
    public function setPreviousCursor(?string $previousCursor): self
    {
        $this->previousCursor = $previousCursor;
        
        return $this;
    }
    
    /**
     * Sets the perPage
     *
     * @param int $maxResults
     *
     * @return self
     */
    public function setMaxResults(int $maxResults): self
    {
        $this->maxResults = $maxResults > 0 ? $maxResults : $this->maxResults;
        
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function paginate(): PaginationResultInterface
    {
        $previousCursor = $nextCursor = null;
        $isPrev = isset($this->previousCursor);
        
        // extend query
        $query = $this->extendQuery(
            $this->queryBuilder,
            $this->previousCursor ?? $this->nextCursor,
            $isPrev ? $this->maxResults : $this->maxResults + 1,
            $isPrev
        );
        
        $statement = $query->execute();
        if (!empty($this->fetchMode)) {
            $statement->setFetchMode(...$this->fetchMode);
        }
        
        $count = $statement->rowCount();
        $this->items = $statement->fetchAll();
        if ($isPrev) {
            $this->items = array_reverse($this->items);
        }
        
        // previous cursor
        if (!empty($this->items)) {
            $firstItem = $this->items[0];
            if ($this->hasPreviousItem($this->queryBuilder, $firstItem)) {
                $previousCursor = $this->buildCursor($firstItem);
            }
        }
        
        // fix max results
        if (!$isPrev && $count > $this->maxResults) {
            array_pop($this->items); // remove last item used for next check
            $count = $this->maxResults;
        }
        
        // next cursor
        $nextCursor = $this->buildCursor($this->items[$count - 1]);
        
        return new PaginationResult($count, $previousCursor, null, $nextCursor);
    }
    
    /**
     * @inheritDoc
     */
    public function getItems()
    {
        yield from $this->items;
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
    
    /**
     * @param string $order
     */
    private function validateOrder(string $order)
    {
        if (!in_array($order, [self::ORDER_ASC, self::ORDER_DESC])) {
            throw new RuntimeException('Unknown sort order');
        }
    }
    
    /**
     * @param QueryBuilder $query
     * @param string|null  $cursor
     * @param int          $maxResults
     * @param bool         $isPrev
     *
     * @return QueryBuilder
     */
    private function extendQuery(QueryBuilder $query, ?string $cursor, int $maxResults, bool $isPrev)
    {
        if (empty($this->primaryKey)) {
            throw new RuntimeException('Primary sorting key is required');
        }
        
        [$primaryKey, $primaryOrder] = $this->primaryKey;
        [$backupKey, $backupOrder] = $this->backupKey ?? [null, null];
        
        if ($isPrev) {
            $primaryOrder = $primaryOrder === self::ORDER_ASC ? self::ORDER_DESC : $primaryOrder;
            $backupOrder = $backupOrder === self::ORDER_ASC ? self::ORDER_DESC : $backupOrder;
        }
        
        $query->addOrderBy($primaryKey, $primaryOrder);
        if ($this->backupKey) {
            $query->addOrderBy($backupKey, $backupOrder);
        }
        
        $query->setMaxResults($maxResults);
        
        if ($cursor) {
            $comp = $isPrev ? '<=' : '>';
            [$primaryValue, $backupValue] = $this->parseCursor($cursor);
            
            $query
                ->where("`$primaryKey` $comp :primaryValue")
                ->setParameter(':primaryValue', $primaryValue);
            
            if ($backupKey) {
                $query->andWhere("`$backupKey` != :backupValue")
                    ->setParameter(':backupValue', $backupValue);
            }
        }
        
        return $query;
    }
    
    /**
     * @param QueryBuilder $query
     * @param array        $currentItem
     *
     * @return bool
     */
    private function hasPreviousItem(QueryBuilder $query, array $currentItem): bool
    {
        [$primaryKey] = $this->primaryKey;
        [$backupKey] = $this->backupKey ?? [null];
        
        $query = (clone $query);
        $primaryValue = $currentItem[$primaryKey];
        $backupValue = $currentItem[$backupKey] ?? null;
        
        if ($this->keysProcessor) {
            $processor = $this->keysProcessor;
            $processor($primaryValue, $backupValue);
        }
        
        $query
            ->where("`$primaryKey` < :primaryValue")
            ->setParameter(':primaryValue', $primaryValue);
        
        if ($backupKey) {
            $query
                ->andWhere("`$backupKey` != :backupValue")
                ->setParameter(':backupValue', $backupValue);
        }
        
        return $query->setMaxResults(1)->execute()->rowCount() > 0;
    }
    
    /**
     * @param array $item
     *
     * @return string
     */
    private function buildCursor(array $item): string
    {
        [$primaryKey] = $this->primaryKey;
        [$backupKey] = $this->backupKey ?? [null];
        
        $segments = [$item[$primaryKey]];
        if ($backupKey) {
            $segments[] = $item[$backupKey];
        }
        
        $cursor = implode(self::CURSOR_GLUE, array_filter($segments));
        
        return self::$encodeCursors ? base64_encode($cursor) : $cursor;
    }
    
    /**
     * @param string $cursor
     *
     * @return array
     */
    private function parseCursor(string $cursor): array
    {
        if (self::$encodeCursors) {
            try {
                $cursor = base64_decode($cursor);
            } catch (Throwable $t) {
                throw new InvalidArgumentException('Failed to decode cursor');
            }
        }
        
        [$primaryValue, $backupValue] = array_replace(
            [null, null],
            explode(self::CURSOR_GLUE, $cursor)
        );
        
        if ($this->keysProcessor) {
            $processor = $this->keysProcessor;
            $processor($primaryValue, $backupValue);
        }
        
        return array_replace([null, null], [$primaryValue, $backupValue]);
    }
}
