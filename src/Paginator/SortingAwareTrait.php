<?php

namespace Tolkam\Pagination\Paginator;

use InvalidArgumentException;
use RuntimeException;

trait SortingAwareTrait
{
    /**
     * @var array|null
     */
    protected ?array $primarySort = null;
    
    /**
     * @var array|null
     */
    protected ?array $backupSort = null;
    
    /**
     * Sets the primary sort
     *
     * @param string $name
     * @param string $order
     *
     * @return self
     */
    public function setPrimarySort(
        string $name,
        string $order = DoctrineSortOrder::ORDER_ASC
    ): self {
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
    public function getPrimarySortKey(): string
    {
        $this->ensurePrimarySortSet();
        
        return $this->primarySort[0];
    }
    
    /**
     * Gets primary sort order
     *
     * @return string
     */
    public function getPrimaryOrder(): string
    {
        $this->ensurePrimarySortSet();
        
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
    public function setBackupSort(
        string $name,
        string $order = DoctrineSortOrder::ORDER_ASC
    ): self {
        $this->validateOrder($order);
        $this->backupSort = [$name, $order];
        
        return $this;
    }
    
    /**
     * Gets backup sort key
     *
     * @return string|null
     */
    public function getBackupSortKey(): ?string
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
     * @param string $order
     */
    private function validateOrder(string $order)
    {
        if (!in_array($order, [DoctrineSortOrder::ORDER_ASC, DoctrineSortOrder::ORDER_DESC])) {
            throw new RuntimeException('Unknown sort order');
        }
    }
    
    /**
     * Checks if primary sort is set
     */
    private function ensurePrimarySortSet()
    {
        if (!$this->primarySort) {
            throw new RuntimeException('Primary sort must be set first');
        }
    }
}
