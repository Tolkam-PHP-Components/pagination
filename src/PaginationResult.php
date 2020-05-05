<?php declare(strict_types=1);

namespace Tolkam\Pagination;

class PaginationResult implements PaginationResultInterface
{
    /**
     * @var int
     */
    protected int $resultsCount = 0;
    
    /**
     * @var mixed
     */
    protected $previousCursor;
    
    /**
     * @var mixed
     */
    protected $currentCursor;
    
    /**
     * @var mixed
     */
    protected $nextCursor;
    
    /**
     * @param int $resultsCount
     * @param     $previousCursor
     * @param     $currentCursor
     * @param     $nextCursor
     */
    public function __construct(int $resultsCount, $previousCursor, $currentCursor, $nextCursor)
    {
        $this->resultsCount = $resultsCount;
        $this->previousCursor = $previousCursor;
        $this->currentCursor = $currentCursor;
        $this->nextCursor = $nextCursor;
    }
    
    /**
     * @inheritDoc
     */
    public function getPreviousCursor()
    {
        return $this->previousCursor;
    }
    
    /**
     * @inheritDoc
     */
    public function getCurrentCursor()
    {
        return $this->currentCursor;
    }
    
    /**
     * @inheritDoc
     */
    public function getNextCursor()
    {
        return $this->nextCursor;
    }
    
    /**
     * @inheritDoc
     */
    public function resultsCount(): int
    {
        return $this->resultsCount;
    }
    
    /**
     * @inheritDoc
     */
    public function isFirst(): bool
    {
        return ($this->resultsCount() || $this->getCurrentCursor()) && !$this->getPreviousCursor();
    }
    
    /**
     * @inheritDoc
     */
    public function hasPages(): bool
    {
        return !!$this->getPreviousCursor() || !!$this->getNextCursor();
    }
}
