<?php declare(strict_types=1);

namespace Tolkam\Pagination\Paginator;

use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Throwable;
use Tolkam\Pagination\PaginationResult;
use Tolkam\Pagination\PaginationResultInterface;
use Tolkam\Pagination\PaginatorInterface;

class DoctrineDbalCursorPaginator implements PaginatorInterface
{
    use SortingAwareTrait;
    
    protected const CURSOR_GLUE = '|';
    
    /**
     * @var QueryBuilder
     */
    protected QueryBuilder $queryBuilder;
    
    /**
     * @var array
     */
    protected array $fetchMode = [];
    
    /**
     * @var array
     */
    protected array $items = [];
    
    /**
     * @var string|null
     */
    protected ?string $after = null;
    
    /**
     * @var string|null
     */
    protected ?string $before = null;
    
    /**
     * Whether to inverse items order
     * @var bool
     */
    protected bool $reverseResults = false;
    
    /**
     * @var callable|null
     */
    protected $keysProcessor = null;
    
    /**
     * @var int
     */
    protected int $maxResults = self::DEFAULT_PER_PAGE;
    
    /**
     * @var bool
     */
    protected bool $encodeCursors = true;
    
    /**
     * @param QueryBuilder $queryBuilder
     */
    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }
    
    /**
     * Reverses results order
     *
     * @return self
     */
    public function reverseResults(): self
    {
        $this->reverseResults = true;
        
        return $this;
    }
    
    /**
     * Whether to encode cursors for pagination result
     * (decoded ones are used for debugging)
     *
     * @param bool $value
     *
     * @return self
     */
    public function encodeCursors(bool $value): self
    {
        $this->encodeCursors = $value;
        
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
     * @param string|null $after
     *
     * @return self
     */
    public function setAfter(?string $after): self
    {
        $this->after = $after;
        
        return $this;
    }
    
    /**
     * Sets the previous cursor
     *
     * @param string|null $before
     *
     * @return self
     */
    public function setBefore(?string $before): self
    {
        $this->before = $before;
        
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
        $currentCursor = $this->before ?? $this->after;
        $previousCursor = $nextCursor = null;
        $isBackwards = isset($this->before);
        $isDescending = $this->primarySort[1] === DoctrineSortOrder::ORDER_DESC;
        $reverseResults = $this->reverseResults;
        
        $query = $this->extendQuery(
            $this->queryBuilder,
            $currentCursor,
            $this->maxResults,
            $isBackwards,
            $isDescending
        );
        
        $statement = $query->execute();
        if (!empty($this->fetchMode)) {
            $statement->setFetchMode(...$this->fetchMode);
        }
        
        $count = $statement->rowCount();
        $this->items = $statement->fetchAll();
        if ($isBackwards && !$reverseResults || !$isBackwards && $reverseResults) {
            $this->items = array_reverse($this->items);
        }
        
        // new cursors
        if (count($this->items)) {
            $query = $this->queryBuilder;
            $firstInSet = $this->items[0];
            $lastInSet = $this->items[$count - 1];
            
            // prev
            $currentItem = $reverseResults ? $lastInSet : $firstInSet;
            if ($this->hasPage($query, $currentItem, !$isDescending)) {
                $previousCursor = $this->buildCursor($currentItem);
            }
            
            // next
            $currentItem = $reverseResults ? $firstInSet : $lastInSet;
            if ($this->hasPage($query, $currentItem, $isDescending)) {
                $nextCursor = $this->buildCursor($currentItem);
            }
        }
        
        return new PaginationResult(
            $count,
            $previousCursor,
            $currentCursor,
            $nextCursor
        );
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
     * @param QueryBuilder $query
     * @param string|null  $cursor
     * @param int          $maxResults
     * @param bool         $isBackwards
     * @param bool         $isDesc
     *
     * @return QueryBuilder
     */
    private function extendQuery(
        QueryBuilder $query,
        ?string $cursor,
        int $maxResults,
        bool $isBackwards,
        bool $isDesc = false
    ) {
        $primaryKey = $this->getPrimarySortKey();
        $primaryOrder = $this->getPrimaryOrder();
        $backupKey = $this->getBackupSortKey();
        $backupOrder = $this->getBackupOrder();
        
        if ($isBackwards) {
            $primaryOrder = $this->inverseOrder($primaryOrder);
            $backupOrder = $this->inverseOrder($backupOrder);
        }
        
        if ($cursor) {
            $comp = $isBackwards ? '<' : '>';
            if ($isDesc) {
                $comp = $comp === '<' ? '>' : '<';
            }
            
            $query->andWhere("`$primaryKey` $comp :primaryValue");
            
            $orSql = "`$primaryKey` = :primaryValue";
            if ($backupKey) {
                $orSql .= " AND `$backupKey` $comp :backupValue";
            }
            $query->orWhere($orSql);
            
            [$primaryValue, $backupValue] = $this->parseCursor($cursor);
            
            // set each param individually to not overwrite existing ones
            $query
                ->setParameter(':primaryValue', $primaryValue)
                ->setParameter(':backupValue', $backupValue);
        }
        
        $query->orderBy($primaryKey, $primaryOrder);
        if ($this->backupSort) {
            $query->addOrderBy($backupKey, $backupOrder);
        }
        
        $query->setMaxResults($maxResults);
        
        return $query;
    }
    
    /**
     * @param QueryBuilder $query
     * @param array        $currentItem
     * @param bool         $isBackwards
     *
     * @return bool
     */
    private function hasPage(
        QueryBuilder $query,
        array $currentItem,
        bool $isBackwards
    ): bool {
        
        $query = clone $query;
        $primaryKey = $this->getPrimarySortKey();
        $primaryOrder = $this->getPrimaryOrder();
        $backupKey = $this->getBackupSortKey();
        $backupOrder = $this->getBackupOrder();
        
        if ($isBackwards) {
            $primaryOrder = $this->inverseOrder($primaryOrder);
            $backupOrder = $this->inverseOrder($backupOrder);
        }
        
        $primaryValue = $currentItem[$primaryKey];
        $backupValue = $currentItem[$backupKey] ?? null;
        
        if ($this->keysProcessor) {
            $processor = $this->keysProcessor;
            $processor($primaryValue, $backupValue);
        }
        
        $comp = $isBackwards ? '<' : '>';
        
        $query->andWhere("`$primaryKey` $comp :primaryValue");
        
        $orSql = "`$primaryKey` = :primaryValue";
        if ($backupKey) {
            $orSql .= " AND `$backupKey` $comp :backupValue";
        }
        $query->orWhere($orSql);
        
        if ($isBackwards) {
            $query->orderBy($primaryKey, $primaryOrder);
            if ($backupKey) {
                $query->addOrderBy($backupKey, $backupOrder);
            }
        }
        
        $query
            ->setParameter(':primaryValue', $primaryValue)
            ->setParameter(':backupValue', $backupValue);
        
        return $query->setMaxResults(1)->execute()->rowCount() !== 0;
    }
    
    /**
     * @param string $order
     *
     * @return string
     */
    private function inverseOrder(string $order): string
    {
        return $order === DoctrineSortOrder::ORDER_ASC
            ? DoctrineSortOrder::ORDER_DESC
            : DoctrineSortOrder::ORDER_ASC;
    }
    
    /**
     * @param array $item
     *
     * @return string
     */
    private function buildCursor(array $item): string
    {
        [$primaryKey] = $this->primarySort;
        [$backupKey] = $this->backupSort ?? [null];
        
        $segments = [$item[$primaryKey]];
        if ($backupKey) {
            $segments[] = $item[$backupKey];
        }
        
        $cursor = implode(self::CURSOR_GLUE, array_filter($segments));
        
        return $this->encodeCursors
            ? $this->encodeCursor($cursor)
            : $cursor;
    }
    
    /**
     * @param string $cursor
     *
     * @return array
     */
    private function parseCursor(string $cursor): array
    {
        if ($this->encodeCursors) {
            $cursor = $this->decodeCursor($cursor);
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
    
    /**
     * Encodes cursor
     *
     * @param string $cursor
     *
     * @return string
     */
    private function encodeCursor(string $cursor): string
    {
        return str_rot13(base64_encode(str_rot13($cursor)));
    }
    
    /**
     * Decodes cursor
     *
     * @param string $cursor
     *
     * @return string
     */
    private function decodeCursor(string $cursor): string
    {
        try {
            return str_rot13(base64_decode(str_rot13($cursor)));
        } catch (Throwable $t) {
            throw new InvalidArgumentException('Failed to decode cursor');
        }
    }
}
