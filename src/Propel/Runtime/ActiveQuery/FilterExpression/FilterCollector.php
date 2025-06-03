<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\FilterExpression;

use Propel\Runtime\ActiveQuery\Criteria;

class FilterCollector
{
    /**
     * @var array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    protected $columnFilters = [];

    /**
     * @return array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    public function getColumnFilters(): array
    {
        return $this->columnFilters;
    }

    /**
     * @param string $andOr
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface $filter
     * @param bool $preferColumnCondition Group AND-filter by column.
     *
     * @return void
     */
    public function addFilterWithConjunction(string $andOr, ColumnFilterInterface $filter, bool $preferColumnCondition = true): void
    {
        $parentFilter = null;
        if ($andOr === Criteria::LOGICAL_OR) {
            $parentFilter = $this->getLastFilter();
        } elseif ($preferColumnCondition) {
            $key = $filter->getLocalColumnName(false);
            $parentFilter = $this->findFilterByColumn($key);
        }

        if (!$parentFilter) {
            $this->columnFilters[] = $filter;
        } else {
            $parentFilter->addFilter($filter, $andOr);
        }
    }

    /**
     * Method to return criteria related to columns in a table.
     *
     * @param string $columnName Column name.
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|null
     */
    public function findFilterByColumn(string $columnName): ?ColumnFilterInterface
    {
        foreach ($this->columnFilters as $filter) {
            if ($filter->getLocalColumnName(false) === $columnName) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return !$this->columnFilters;
    }

    /**
     * @return void
     */
    public function clear()
    {
        $this->columnFilters = [];
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\FilterCollector $filterCollector
     * @param bool $isOr
     *
     * @return void
     */
    public function merge(FilterCollector $filterCollector, bool $isOr): void
    {
        if ($isOr && $filterCollector->columnFilters) {
            $firstFilter = array_shift($filterCollector->columnFilters);
            $this->addFilterWithConjunction(Criteria::LOGICAL_OR, $firstFilter, false);
        }
        foreach ($filterCollector->getColumnFilters() as $key => $filter) {
            $this->addFilterWithConjunction(Criteria::LOGICAL_AND, $filter, true);
        }
    }

    /**
     * @return int
     */
    public function countColumnFilters(): int
    {
        return count($this->columnFilters);
    }

    /**
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|null A Criterion or null no Criterion is added.
     */
    public function getLastFilter(): ?ColumnFilterInterface
    {
        return end($this->columnFilters) ?: null;
    }

    /**
     * @return array<string>
     */
    public function getColumnExpressionsInQuery(): array
    {
        return array_map(fn ($c) => $c->getLocalColumnName(false), $this->columnFilters);
    }

    /**
     * @return array<string, \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    public function getColumnFiltersByColumn(): array
    {
        $map = [];
        foreach ($this->columnFilters as $filter) {
            $map[$filter->getLocalColumnName(false)] = $filter;
        }

        return $map;
    }

    /**
     * @param string|null $defaultTableAlias
     *
     * @return array<string|null, array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>> array(table => array(table.column1, table.column2))
     */
    public function groupFiltersByTable(?string $defaultTableAlias): array
    {
        $tables = [];
        foreach ($this->columnFilters as $filter) {
            $tableAlias = $filter->getTableAlias() ?? $defaultTableAlias;
            $tables[$tableAlias][] = $filter;
        }

        return $tables;
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\FilterCollector $collector
     *
     * @return bool
     */
    public function equals(FilterCollector $collector): bool
    {
        if (count($this->columnFilters) !== count($collector->columnFilters)) {
            return false;
        }

        foreach ($collector->columnFilters as $otherFilter) {
            foreach ($this->getColumnFilters() as $thisFilter) {
                if ($thisFilter->equals($otherFilter)) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return implode(' AND ', $this->columnFilters);
    }

    /**
     * @return void
     */
    public function __clone()
    {
        $this->columnFilters = array_map(fn (ColumnFilterInterface $f) => clone $f, $this->columnFilters);
    }
}
