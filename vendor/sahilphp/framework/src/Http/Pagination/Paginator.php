<?php

declare(strict_types=1);

namespace Sahil\Http\Pagination;

use Sahil\Http\Request;
use Sahil\Orm\PageResult;

/**
 * Builds canonical pagination metadata for JSON APIs, independent of the
 * ORM layer where page results are produced.
 *
 * The standard keys are:
 *
 *   - total:          total number of items
 *   - perPage:        items per page
 *   - currentPage:    current page (1-based)
 *   - lastPage:       last page number
 *   - hasMorePages:   whether more pages exist
 *   - nextPageUrl:    next-page URL or null
 *   - prevPageUrl:    previous-page URL or null
 */
final class Paginator
{
    /**
     * @param int|null $perPage explicit page size, overriding the result's own
     */
    public function __construct(
        private readonly PageResult $page,
        private readonly ?int $perPage = null,
    ) {
    }

    /**
     * Build a paginator, honouring a `perPage` query parameter (1–1000).
     */
    public static function forRequest(Request $request, PageResult $page): self
    {
        $requested = $request->queryParameter('perPage');

        return new self(
            page: $page,
            perPage: $requested === null ? null : max(1, min((int) $requested, 1000)),
        );
    }

    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'perPage' => $this->perPage(),
            'currentPage' => $this->currentPage(),
            'lastPage' => $this->lastPage(),
            'hasMorePages' => $this->hasMorePages(),
            'nextPageUrl' => $this->nextPageUrl(),
            'prevPageUrl' => $this->previousPageUrl(),
        ];
    }

    public function json(): array
    {
        return $this->toArray();
    }

    public function total(): int
    {
        return $this->page->total;
    }

    public function perPage(): int
    {
        return $this->perPage ?? $this->page->perPage;
    }

    public function currentPage(): int
    {
        return $this->page->currentPage;
    }

    public function lastPage(): int
    {
        return $this->page->lastPage();
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
    }

    /**
     * @return list<\Sahil\Orm\Model>
     */
    public function items(): array
    {
        return $this->page->result->all();
    }

    private function nextPageUrl(): ?string
    {
        if (!$this->hasMorePages()) {
            return null;
        }

        return '/api/page='.($this->currentPage() + 1).'&perPage='.$this->perPage();
    }

    private function previousPageUrl(): ?string
    {
        if (!$this->hasPreviousPage()) {
            return null;
        }

        return '/api/page='.($this->currentPage() - 1).'&perPage='.$this->perPage();
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage() > 1;
    }
}
