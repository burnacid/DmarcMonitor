<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Click-to-sort table columns. The chosen column and direction are kept in
 * the page address (?sort=…&dir=…), left out while the table is in its
 * default order. Values from the address are only used when the component
 * lists them as sortable, so they can be used to build queries.
 */
trait WithSorting
{
    #[Url(as: 'sort')]
    public string $sortBy = '';

    #[Url(as: 'dir')]
    public string $sortDirection = '';

    /**
     * The sortable columns, each with the direction its first click sorts in.
     *
     * @return array<string, 'asc'|'desc'>
     */
    abstract protected function sortableColumns(): array;

    /**
     * The column and direction used until a column header is clicked.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    abstract protected function defaultSort(): array;

    /**
     * Clicking the sorted column flips its direction; another column starts
     * in its own first direction.
     */
    public function sort(string $column): void
    {
        $columns = $this->sortableColumns();

        if (! array_key_exists($column, $columns)) {
            return;
        }

        $current = $this->currentSort;

        $this->sortBy = $column;
        $this->sortDirection = $current['column'] === $column
            ? ($current['direction'] === 'asc' ? 'desc' : 'asc')
            : $columns[$column];

        // The computed value read above is cached for this request; drop it so the render uses the new sort.
        unset($this->currentSort);

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * @return array{column: string, direction: 'asc'|'desc'}
     */
    #[Computed]
    public function currentSort(): array
    {
        [$defaultColumn, $defaultDirection] = $this->defaultSort();
        $columns = $this->sortableColumns();

        if (! array_key_exists($this->sortBy, $columns)) {
            return ['column' => $defaultColumn, 'direction' => $defaultDirection];
        }

        return [
            'column' => $this->sortBy,
            'direction' => in_array($this->sortDirection, ['asc', 'desc'], true) ? $this->sortDirection : $columns[$this->sortBy],
        ];
    }
}
