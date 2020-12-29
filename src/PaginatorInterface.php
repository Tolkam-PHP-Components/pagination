<?php declare(strict_types=1);

namespace Tolkam\Pagination;

use Generator;

interface PaginatorInterface
{
    public const DEFAULT_PER_PAGE = 500;
    
    /**
     * Paginates and returns the result
     *
     * @return PaginationResultInterface
     */
    public function paginate(): PaginationResultInterface;
    
    /**
     * Gets paginated items
     *
     * @return Generator|[]
     */
    public function getItems();
}
