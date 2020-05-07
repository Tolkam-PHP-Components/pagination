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
     * @var array|null
     */
    protected ?array $primarySort = null;
    
    /**
     * @var array|null
     */
    protected ?array $backupSort = null;
    
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
     * Sets the primary sort
     *
     * @param string $name
     * @param string $order
     *
     * @return self
     */
    public function setPrimarySort(string $name, string $order = self::ORDER_ASC): self
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Primary sort column name can not be empty');
        }
        
        $this->validateOrder($order);
        $this->primarySort = [$name, $order];
        
        return $this;
    }
    
    /**
     * Gets primary sort key
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        if (!$this->primarySort) {
            throw new RuntimeException('Primary sort must be set first');
        }
        
        return $this->primarySort[0];
    }
    
    /**
     * Gets primary sort order
     *
     * @return string
     */
    public function getPrimaryOrder(): string
    {
        return $this->primarySort[1];
    }
    
    /**
     * Sets the backup sort
     *
     * @param string $name
     * @param string $order
     *
     * @return self
     */
    public function setBackupSort(string $name, string $order = self::ORDER_ASC): self
    {
        $this->validateOrder($order);
        $this->backupSort = [$name, $order];
        
        return $this;
    }
    
    /**
     * Gets backup sort key
     *
     * @return string|null
     */
    public function getBackupKey(): ?string
    {
        return $this->backupSort[0] ?? null;
    }
    
    /**
     * Gets backup sort order
     *
     * @return string|null
     */
    public function getBackupOrder(): ?string
    {
        return $this->backupSort[1] ?? null;
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
        $previousCursor = $currentCursor = $nextCursor = null;
        $isBackwards = isset($this->before);
        $isDescending = $this->primarySort[1] === self::ORDER_DESC;
        $reverseResults = $this->reverseResults;
        
        $query = $this->extendQuery(
            $this->queryBuilder,
            $this->before ?? $this->after,
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
        $primaryKey = $this->getPrimaryKey();
        $primaryOrder = $this->getPrimaryOrder();
        $backupKey = $this->getBackupKey();
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
            
            [$primaryValue, $backupValue] = $this->parseCursor($cursor);
            
            $sql = "`$primaryKey` = :primaryValue";
            if ($backupKey) {
                $sql .= " AND `$backupKey` $comp :backupValue";
            }
            
            $query
                ->where($sql)
                ->orWhere("`$primaryKey` $comp :primaryValue");
            
            $query->setParameters([
                ':primaryValue' => $primaryValue,
                ':backupValue' => $backupValue,
            ]);
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
        $primaryKey = $this->getPrimaryKey();
        $primaryOrder = $this->getPrimaryOrder();
        $backupKey = $this->getBackupKey();
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
        $sql = "`$primaryKey` = :primaryValue";
        if ($backupKey) {
            $sql .= " AND `$backupKey` $comp :backupValue";
        }
        
        $query
            ->where($sql)
            ->orWhere("`$primaryKey` $comp :primaryValue");
        
        if ($isBackwards) {
            $query->orderBy($primaryKey, $primaryOrder);
            if ($backupKey) {
                $query->addOrderBy($backupKey, $backupOrder);
            }
        }
        
        $query->setParameters([
            ':primaryValue' => $primaryValue,
            ':backupValue' => $backupValue,
        ]);
        
        return $query->setMaxResults(1)->execute()->rowCount() !== 0;
    }
    
    /**
     * @param string $order
     *
     * @return string
     */
    private function inverseOrder(string $order): string
    {
        return $order === self::ORDER_ASC ? self::ORDER_DESC : self::ORDER_ASC;
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
