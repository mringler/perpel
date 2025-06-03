<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\FilterExpression;

use LogicException;

/**
 * A FilterCollector that can combine with a nested FilterCollector to create
 * nested conditions like (A AND (B OR C)).
 *
 * After calling {@see static::combineFilters()}, all filter operations are
 * propageted to a child instance which is then combined with this filter
 * when {@see static::endCombineFilters()} is called on this instance.
 */
class FilterCollectorCombiner extends FilterCollector
{
    /**
     * FilterCollector to combine complex filters on.
     *
     * If set, all filter operations are delegated to this instance.
     *
     * @var \Propel\Runtime\ActiveQuery\FilterExpression\FilterCollectorCombiner|null
     */
    protected $nestedCombiner;

    /**
     * Operator to combine current nested combiner with this filters.
     *
     * @var string|null
     */
    protected $combineAndOr;

    /**
     * @param string $andOr
     *
     * @return void
     */
    public function combineFilters(string $andOr): void
    {
        if ($this->nestedCombiner) {
            $this->nestedCombiner->combineFilters($andOr);
        } else {
            $this->nestedCombiner = new self();
            $this->combineAndOr = $andOr;
        }
    }

    /**
     * @return bool
     */
    public function endCombineFilters(): bool
    {
        if ($this->nestedCombiner === null) {
            return false;
        }
        if (!$this->nestedCombiner->endCombineFilters()) {
            $this->columnFilters = $this->mergeCombiner($this->columnFilters);
            $this->nestedCombiner = null;
            $this->combineAndOr = null;
        }

        return true;
    }

    /*
    (a1 && b1) || (a2 && b2)
    a1[ &&b1, ||a2[&&b2] ] -> ((a1 && b1) || (a2 && b2))

     */

    /**
     * @param array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface> $target
     *
     * @return array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    protected function mergeCombiner(array $target): array
    {
        if (!$this->nestedCombiner || $this->nestedCombiner->isEmpty()) {
            return $target;
        }
        /** @var non-empty-array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface> $combinerFilters */
        $combinerFilters = $this->nestedCombiner->mergeCombiner($this->nestedCombiner->columnFilters); // recurse
        $firstFilter = array_shift($combinerFilters);
        foreach ($combinerFilters as $filter) {
            $firstFilter->addAnd($filter);
        }

        if ($this->combineAndOr === ClauseList::OR_OPERATOR_LITERAL && (bool)$target) {
            end($target)->addOr($firstFilter);
        } else {
            $target[] = $firstFilter;
        }

        return $target;
    }

    /**
     * @return array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    #[\Override]
    public function getColumnFilters(): array
    {
        return $this->mergeCombiner(parent::getColumnFilters());
    }

    /**
     * @param string $andOr
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface $filter
     * @param bool $preferColumnCondition Group AND-filter by column.
     *
     * @return void
     */
    #[\Override]
    public function addFilterWithConjunction(string $andOr, ColumnFilterInterface $filter, bool $preferColumnCondition = true): void
    {
        if ($this->nestedCombiner) {
            $this->nestedCombiner->addFilterWithConjunction($andOr, $filter, $preferColumnCondition);
        } else {
            parent::addFilterWithConjunction($andOr, $filter, $preferColumnCondition);
        }
    }

    /**
     * Method to return criteria related to columns in a table.
     *
     * @param string $columnName Column name.
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|null
     */
    #[\Override]
    public function findFilterByColumn(string $columnName): ?ColumnFilterInterface
    {
        return parent::findFilterByColumn($columnName) ?? ($this->nestedCombiner ? $this->nestedCombiner->findFilterByColumn($columnName) : null);
    }

    /**
     * @return bool
     */
    #[\Override]
    public function isEmpty(): bool
    {
        return parent::isEmpty() && (!$this->nestedCombiner || $this->nestedCombiner->isEmpty());
    }

    /**
     * @return void
     */
    #[\Override]
    public function clear()
    {
        parent::clear();
        $this->nestedCombiner = null;
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\FilterCollector $filterCollector
     * @param bool $isOr
     *
     * @return void
     */
    #[\Override]
    public function merge(FilterCollector $filterCollector, bool $isOr): void
    {
        if ($this->nestedCombiner) {
            $this->nestedCombiner->merge($filterCollector, $isOr);
        } else {
            parent::merge($filterCollector, $isOr);
            $this->nestedCombiner = $filterCollector instanceof FilterCollectorCombiner ? $filterCollector->nestedCombiner : null;
        }
    }

    /**
     * @return int
     */
    #[\Override]
    public function countColumnFilters(): int
    {
        return parent::countColumnFilters() + ($this->nestedCombiner ? $this->nestedCombiner->countColumnFilters() : 0);
    }

    /**
     * @return array<string>
     */
    #[\Override]
    public function getColumnExpressionsInQuery(): array
    {
        return array_merge(parent::getColumnExpressionsInQuery(), $this->nestedCombiner ? $this->nestedCombiner->getColumnExpressionsInQuery() : []);
    }

    /**
     * @return array<string, \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    #[\Override]
    public function getColumnFiltersByColumn(): array
    {
        return array_merge(parent::getColumnFiltersByColumn(), $this->nestedCombiner ? $this->nestedCombiner->getColumnFiltersByColumn() : []);
    }

    /**
     * @param string|null $defaultTableAlias
     *
     * @throws \LogicException
     *
     * @return array<string|null, array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>> array(table => array(table.column1, table.column2))
     */
    #[\Override]
    public function groupFiltersByTable(?string $defaultTableAlias): array
    {
        if ($this->nestedCombiner) {
            throw new LogicException('Cannot group filters with unfinished combine');
        }

        return parent::groupFiltersByTable($defaultTableAlias);
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\FilterCollector $collector
     *
     * @return bool
     */
    #[\Override]
    public function equals(FilterCollector $collector): bool
    {
        if (!parent::equals($collector)) {
            return false;
        }

        return $this->nestedCombiner
            ? $collector instanceof static && $collector->nestedCombiner && $this->nestedCombiner->equals($collector->nestedCombiner)
            : !($collector instanceof static && $collector->nestedCombiner);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return parent::__toString() . ($this->nestedCombiner ? ' AND (' . $this->nestedCombiner->__toString() . ' ... )' : '');
    }

    /**
     * @return void
     */
    #[\Override]
    public function __clone()
    {
        parent::__clone();
        $this->nestedCombiner = $this->nestedCombiner ? clone $this->nestedCombiner : null;
    }
}
