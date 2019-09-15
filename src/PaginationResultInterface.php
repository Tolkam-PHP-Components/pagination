<?php declare(strict_types=1);

namespace Tolkam\Pagination;

interface PaginationResultInterface
{
    /**
     * Gets previous page cursor
     *
     * @return mixed
     */
    public function getPreviousCursor();
    
    /**
     * Gets current page cursor
     *
     * @return mixed
     */
    public function getCurrentCursor();
    
    /**
     * Gets next page cursor
     *
     * @return mixed
     */
    public function getNextCursor();
    
    /**
     * Counts page items
     *
     * @return int
     */
    public function resultsCount(): int;
    
    /**
     * Checks if is first page
     *
     * @return bool
     */
    public function isFirst(): bool;
    
    /**
     * Checks if has previous or next pages
     *
     * @return bool
     */
    public function hasPages(): bool;
}