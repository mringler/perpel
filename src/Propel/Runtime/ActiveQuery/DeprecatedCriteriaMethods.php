<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery;

use LogicException;
use Propel\Runtime\ActiveQuery\Exception\UnknownColumnException;
use Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface;
use Propel\Runtime\ActiveQuery\FilterExpression\FilterClauseLiteralWithColumns;
use Propel\Runtime\Map\ColumnMap;
use Propel\Runtime\Propel;

class DeprecatedCriteriaMethods extends Criteria
{
    /**
     * @var \Propel\Runtime\ActiveQuery\Criteria
     */
    protected $criteria;

    /**
     * Storage for Criterions expected to be combined
     *
     * @var array<string, \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    protected $namedCriterions = [];

    /**
     * @var bool
     */
    protected $useTransaction = false;

    /**
     * @var bool
     */
    protected $singleRecord = false;

    /**
     * @var bool
     */
    protected $isWithOneToMany = false;

    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria
     */
    public function __construct(Criteria $criteria)
    {
        $this->criteria = $criteria;
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria
     *
     * @return void
     */
    public function setCriteria(Criteria $criteria): void
    {
        $this->criteria = $criteria;
    }

    /**
     * @return array
     */
    public function getNamedCriterions(): array
    {
        return $this->namedCriterions;
    }

    /**
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    #[\Override]
    public function clear()
    {
        $this->namedCriterions = [];
        $this->useTransaction = false;
        $this->singleRecord = false;

        return $this->criteria;
    }

    /**
     * Ensures deep cloning of attached objects
     *
     * @return void
     */
    #[\Override]
    public function __clone()
    {
        $this->namedCriterions = array_map(fn ($c) => clone $c, $this->namedCriterions);
    }

    /**
     * @deprecated use aptly named {@see static::getColumnFilters()}.
     *
     * @return array<\Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface>
     */
    public function getMap(): array
    {
        return $this->criteria->filterCollector->getColumnFiltersByColumn();
    }

    /**
     * @deprecated if needed, resolve manually from filterColumns or updateValues.
     *
     * Get the keys of the criteria map, i.e. the list of columns bearing a condition
     * <code>
     * print_r($c->keys());
     *  => array('book.price', 'book.title', 'author.first_name')
     * </code>
     *
     * @return array
     */
    public function keys(): array
    {
        return array_merge(
            $this->criteria->updateValues->getColumnExpressionsInQuery(),
            $this->criteria->filterCollector->getColumnExpressionsInQuery(),
        );
    }

    /**
     * @deprecated use {@see Criteria::hasUpdateValue()}
     *
     * Does this Criteria object contain the specified key?
     *
     * @param string $column [table.]column
     *
     * @return bool True if this Criteria object contain the specified key.
     */
    public function containsKey(string $column): bool
    {
        return $this->criteria->hasUpdateValue($column);
    }

    /**
     * @deprecated use {@see static::getUpdateValue()} and check against null yourself.
     *
     * @param string $columnName [table.]column
     *
     * @return bool True if this Criteria object contain the specified key and a value for that key
     */
    public function keyContainsValue(string $columnName): bool
    {
        return $this->criteria->getUpdateValue($columnName) !== null;
    }

    /**
     * @deprecated use {@see static::findFilterByColumn()} or {@see static::getUpdateValueForColumn()}
     * Method to return criteria related to columns in a table.
     *
     * Make sure you call containsKey($column) prior to calling this method,
     * since no check on the existence of the $column is made in this method.
     *
     * @param string $column Column name.
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|null A Criterion object.
     */
    public function getCriterion(string $column): ?ColumnFilterInterface
    {
        return $this->criteria->findFilterByColumn($column);
    }

    /**
     * @deprecated use aptly named {@see static::getLastFilter()}
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|null A Criterion or null no Criterion is added.
     */
    public function getLastCriterion(): ?ColumnFilterInterface
    {
        return $this->criteria->getLastFilter();
    }

    /**
     * @deprecated use {@see static::getUpdateValuesByTable()} or {@see static::getFiltersByTable()}.
     *
     * Shortcut method to get an array of columns indexed by table.
     * <code>
     * print_r($c->getTablesColumns());
     *  => array(
     *       'book' => array('book.price', 'book.title'),
     *       'author' => array('author.first_name')
     *     )
     * </code>
     *
     * @return array array(table => array(table.column1, table.column2))
     */
    public function getTablesColumns(): array
    {
        $tables = [];
        foreach ($this->criteria->filterCollector->getColumnFilters() as $filter) {
            $tableName = $filter->getTableAlias();
            $tables[$tableName][] = $filter->getLocalColumnName(false);
        }
        foreach ($this->criteria->updateValues->getColumnExpressionsInQuery() as $columnExpression) {
            $tableName = substr($columnExpression, 0, strrpos($columnExpression, '.') ?: null);
            $tables[$tableName][] = $columnExpression;
        }

        return $tables;
    }

    /**
     * @deprecated get update value (or filter) and resolve table alias manually.
     *
     * Method to return a String table name.
     *
     * @param string $name The name of the key.
     *
     * @return string|null The value of table for criterion at key.
     */
    public function getTableName(string $name): ?string
    {
        $updateColumn = $this->criteria->updateValues->getUpdateColumn($name);

        return $updateColumn ? $updateColumn->getTableAlias() : null;
    }

    /**
     * @deprecated Use aptly named {@see static::getUpdateValue()}.
     *
     * An alias to getValue() -- exposing a Hashtable-like interface.
     *
     * @param string $key An Object.
     *
     * @return mixed The value within the Criterion (not the Criterion object).
     */
    public function get(string $key)
    {
        return $this->criteria->getUpdateValue($key);
    }

    /**
     * @deprecated Old interface should not be used anymore.
     *
     * Overrides Hashtable put, so that this object is returned
     * instead of the value previously in the Criteria object.
     * The reason is so that it more closely matches the behavior
     * of the add() methods. If you want to get the previous value
     * then you should first Criteria.get() it yourself. Note, if
     * you attempt to pass in an Object that is not a String, it will
     * throw a NPE. The reason for this is that none of the add()
     * methods support adding anything other than a String as a key.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    public function put(string $key, $value)
    {
        return $this->criteria->addFilter($key, $value);
    }

    /**
     * @deprecated old interface should not be used anymore.
     *
     * Copies all of the mappings from the specified Map to this Criteria
     * These mappings will replace any mappings that this Criteria had for any
     * of the keys currently in the specified Map.
     *
     * if the map was another Criteria, its attributes are copied to this
     * Criteria, overwriting previous settings.
     *
     * @param mixed $t Mappings to be stored in this map.
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    public function putAll($t)
    {
        if (is_array($t)) {
            foreach ($t as $key => $value) {
                if ($value instanceof ColumnFilterInterface) {
                    $this->criteria->filterCollector->addFilterWithConjunction(Criteria::LOGICAL_AND, $value, false);
                } else {
                    $this->put($key, $value);
                }
            }
        } elseif ($t instanceof Criteria) {
            $this->criteria->joins = $t->joins;
        }

        return $this->criteria;
    }

    /**
     * This method creates a new criterion but keeps it for later use with combine()
     * Until combine() is called, the condition is not added to the query
     *
     * <code>
     * $crit = new Criteria();
     * $crit->addCond('cond1', $column1, $value1, Criteria::GREATER_THAN);
     * $crit->addCond('cond2', $column2, $value2, Criteria::EQUAL);
     * $crit->combine(array('cond1', 'cond2'), Criteria::LOGICAL_OR);
     * </code>
     *
     * Any comparison can be used.
     *
     * The name of the table must be used implicitly in the column name,
     * so the Column name must be something like 'TABLE.id'.
     *
     * @param string $name name to combine the criterion later
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|\Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression|string $columnOrClause The column to run the comparison on, or AbstractCriterion object.
     * @param mixed|null $value
     * @param string|null $comparison A String.
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria A modified Criteria object.
     */
    public function addCond(string $name, $columnOrClause, $value = null, ?string $comparison = null)
    {
        $this->namedCriterions[$name] = $this->criteria->buildFilter($columnOrClause, $value, $comparison);

        return $this->criteria;
    }

    /**
     * Adds a condition on a column based on a pseudo SQL clause
     * but keeps it for later use with combine()
     * Until combine() is called, the condition is not added to the query
     * Uses introspection to translate the column phpName into a fully qualified name
     * <code>
     * $c->condition('cond1', 'b.Title = ?', 'foo');
     * </code>
     *
     * @param string $conditionName A name to store the condition for a later combination with combine()
     * @param string $clause The pseudo SQL clause, e.g. 'AuthorId = ?'
     * @param mixed $value A value for the condition
     * @param mixed $bindingType A value for the condition
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    public function condition(string $conditionName, string $clause, $value = null, $bindingType = null)
    {
        $this->addCond($conditionName, $this->criteria->buildFilterForClause($clause, $value, $bindingType), null, $bindingType);

        return $this->criteria;
    }

    /**
     * @deprecated use aptly named {@see static::buildFilterForClause()}
     *
     * @param string $clause The pseudo SQL clause, e.g. 'AuthorId = ?'
     * @param mixed $value A value for the condition
     * @param int|null $bindingType
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface a Criterion object
     */
    public function getCriterionForClause(string $clause, $value, ?int $bindingType = null): ColumnFilterInterface
    {
        return $this->buildFilterForClause($clause, $value, $bindingType);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function hasCond(string $name): bool
    {
        return isset($this->namedCriterions[$name]);
    }

    /**
     * @param string $name
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface
     */
    public function getCond(string $name): ColumnFilterInterface
    {
        return $this->namedCriterions[$name];
    }

    /**
     * Combine several named criterions with a logical operator
     *
     * @param array $criterions array of the name of the criterions to combine
     * @param string $operator logical operator, either Criteria::LOGICAL_AND, or Criteria::LOGICAL_OR
     * @param string|null $name optional name to combine the criterion later
     *
     * @throws \Propel\Runtime\Exception\LogicException
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    public function combine(array $criterions = [], string $operator = self::LOGICAL_AND, ?string $name = null)
    {
        $namedCriterions = [];
        foreach ($criterions as $key) {
            if (array_key_exists($key, $this->namedCriterions)) {
                $namedCriterions[] = $this->namedCriterions[$key];
                unset($this->namedCriterions[$key]);
            } else {
                throw new LogicException(sprintf('Cannot combine unknown condition %s', $key));
            }
        }
        $operatorMethod = (strtoupper($operator) === self::LOGICAL_AND) ? 'addAnd' : 'addOr';
        $firstCriterion = array_shift($namedCriterions);
        foreach ($namedCriterions as $criterion) {
            $firstCriterion->$operatorMethod($criterion);
        }
        if ($name === null) {
            $this->criteria->addAnd($firstCriterion, null, null);
        } else {
            $this->addCond($name, $firstCriterion, null, null);
        }

        return $this->criteria;
    }

    /**
     * @deprecated use aptly named Criteria::addSubquery().
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria $subQueryCriteria Criteria to build the subquery from
     * @param string|null $alias alias for the subQuery
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    public function addSelectQuery(Criteria $subQueryCriteria, ?string $alias = null)
    {
        return $this->criteria->addSubquery($subQueryCriteria, $alias);
    }

    /**
     * @deprecated use aptly named {@see static::removeUpdateValue()}
     *
     * @param string $key A string with the key to be removed.
     *
     * @return mixed|null The removed value.
     */
    public function remove(string $key)
    {
        return $this->criteria->updateValues->removeUpdateValue($key);
    }

    /**
     * @deprecated use {@see static::countColumnFilters()}
     * or {@see static::countUpdateValues()}.
     *
     * Returns the size (count) of this criteria.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->criteria->countColumnFilters() + $this->criteria->countUpdateValues();
    }

    /**
     * @deprecated use aptly named {@see static::buildFilter()}
     *
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|\Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression|string|null $columnOrClause A Criterion, or a SQL clause with a question mark placeholder, or a column name
     * @param mixed|null $value The value to bind in the condition
     * @param string|int|null $comparison A Criteria class constant, or a PDO::PARAM_ class constant
     * @param bool $hasAccessToOutputColumns If AS columns can be used in the statement (for example in HAVING clauses)
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface
     */
    public function getCriterionForCondition(
        $columnOrClause,
        $value = null,
        $comparison = null,
        bool $hasAccessToOutputColumns = false
    ): ColumnFilterInterface {
        return $this->criteria->buildFilter($columnOrClause, $value, $comparison, $hasAccessToOutputColumns);
    }

    /**
     * @deprecated resolve the tableMap yourself and use Criteria::quoteColumnIdentifier().
     *
     * Quotes identifier based on $this->isIdentifierQuotingEnabled() and $tableMap->isIdentifierQuotingEnabled.
     *
     * @param string $string
     * @param string $tableName
     *
     * @return string
     */
    public function quoteIdentifier(string $string, string $tableName = ''): string
    {
        if ($this->criteria->isIdentifierQuotingEnabled()) {
            return $this->criteria->getAdapter()->quote($string);
        }

        //find table name and ask tableMap if quoting is enabled
        $pos = strrpos($string, '.');
        if (!$tableName && $pos !== false) {
            $tableName = substr($string, 0, $pos);
        }

        $tableMapName = $this->criteria->getTableForAlias($tableName) ?: $tableName;

        if ($tableMapName) {
            $dbMap = $this->criteria->getDatabaseMap();
            if ($dbMap->hasTable($tableMapName)) {
                $tableMap = $dbMap->getTable($tableMapName);
                if ($tableMap->isIdentifierQuotingEnabled()) {
                    return $this->criteria->getAdapter()->quote($string);
                }
            }
        }

        return $string;
    }

    /**
     * @deprecated use ModelCriteria::replaceColumnNames()
     *
     * @param string $sql
     *
     * @return bool
     */
    public function replaceNames(string &$sql): bool
    {
        $normalizedExpression = $this->criteria->normalizeFilterExpression($sql);
        $sql = $normalizedExpression->getNormalizedFilterExpression();

        return (bool)$normalizedExpression->getReplacedColumns();
    }

    /**
     * @deprecated get primary keys in a reliable way from TableMap.
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria|null $criteria
     *
     * @return \Propel\Runtime\Map\ColumnMap|null
     */
    public function getPrimaryKey(?Criteria $criteria = null): ?ColumnMap
    {
        if (!$criteria) {
            $criteria = $this->criteria;
        }
        // Assume all the keys are for the same table.
        $key = $this->criteria->updateValues->getColumnExpressionsInQuery()[0];
        $table = $criteria->getDeprecatedMethods()->getTableName($key);

        $pk = null;

        if ($table) {
            $dbMap = Propel::getServiceContainer()->getDatabaseMap($criteria->getDbName());

            $pks = $dbMap->getTable($table)->getPrimaryKeys();
            if ($pks) {
                $pk = array_shift($pks);
            }
        }

        return $pk;
    }

    /**
     * @deprecated get the filter or update value manually and lookup operator/comparison.
     *
     * Method to return a comparison String.
     *
     * @param string $key String name of the key.
     *
     * @return string|null A String with the value of the object at key.
     */
    public function getComparison(string $key): ?string
    {
        return null;
    }

    /**
     * Creates a Criterion object based on a list of existing condition names and a comparator
     *
     * @param array<string> $conditions The list of condition names, e.g. array('cond1', 'cond2')
     * @param string|null $operator An operator, Criteria::LOGICAL_AND (default) or Criteria::LOGICAL_OR
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface A Criterion or ModelCriterion object
     */
    public function getCriterionForConditions(array $conditions, ?string $operator = null): ColumnFilterInterface
    {
        $operator = ($operator === null) ? Criteria::LOGICAL_AND : $operator;
        $this->combine($conditions, $operator, 'propel_temp_name');
        $criterion = $this->namedCriterions['propel_temp_name'];
        unset($this->namedCriterions['propel_temp_name']);

        return $criterion;
    }

    /**
     * @deprecated value is never used.
     *
     * Will force the sql represented by this criteria to be executed within
     * a transaction. This is here primarily to support the oid type in
     * postgresql. Though it can be used to require any single sql statement
     * to use a transaction.
     *
     * @param bool $v
     *
     * @return $this
     */
    public function setUseTransaction(bool $v)
    {
        $this->useTransaction = $v;

        return $this;
    }

    /**
     * @deprecated value is never used.
     *
     * Whether the sql command specified by this criteria must be wrapped
     * in a transaction.
     *
     * @return bool
     */
    public function isUseTransaction(): bool
    {
        return $this->useTransaction;
    }

    /**
     * @deprecated value is never used.
     *
     * Set single record? Set this to <code>true</code> if you expect the query
     * to result in only a single result record (the default behaviour is to
     * throw a PropelException if multiple records are returned when the query
     * is executed). This should be used in situations where returning multiple
     * rows would indicate an error of some sort. If your query might return
     * multiple records but you are only interested in the first one then you
     * should be using setLimit(1).
     *
     * @param bool $b Set to TRUE if you expect the query to select just one record.
     *
     * @return $this Modified Criteria object (for fluent API)
     */
    public function setSingleRecord(bool $b)
    {
        $this->singleRecord = $b;

        return $this;
    }

    /**
     * @deprecated value is never used.
     *
     * Is single record?
     *
     * @return bool True if a single record is being returned.
     */
    public function isSingleRecord(): bool
    {
        return $this->singleRecord;
    }

    /**
     * @return bool
     */
    public function isWithOneToMany(): bool
    {
        return $this->isWithOneToMany;
    }

    /**
     * @deprecated use FilterClauseLiteralWithColumns::convertValueForColumn()
     *
     * @param mixed $value The value to convert
     * @param \Propel\Runtime\Map\ColumnMap $colMap The ColumnMap object
     *
     * @return mixed The converted value
     */
    public function convertValueForColumn($value, ColumnMap $colMap)
    {
        return FilterClauseLiteralWithColumns::convertValueForColumn($value, $colMap);
    }

    /**
     * @deprecated Use {@see ModelCriteria::resolveColumn()}
     *
     * @param string $columnName String representing the column name in a pseudo SQL clause, e.g. 'Book.Title'
     * @param bool $failSilently
     *
     * @return array List($columnMap, $localColumnName)
     */
    public function getColumnFromName(string $columnName, bool $failSilently = true): array
    {
        $resolvedColumn = $this->criteria->resolveColumn($columnName, $failSilently);

        return [$resolvedColumn->hasColumnMap() ? $resolvedColumn->getColumnMap() : null, $resolvedColumn->getColumnExpressionInQuery()];
    }

    /**
     * Return a fully qualified column name corresponding to a simple column phpName
     * Uses model alias if it exists
     * Warning: restricted to the columns of the main model
     * e.g. => 'Title' => 'book.TITLE'
     *
     * @param string $columnName the Column phpName, without the table name
     *
     * @throws \Propel\Runtime\ActiveQuery\Exception\UnknownColumnException
     *
     * @return \Propel\Runtime\Map\ColumnMap
     */
    public function getColumnMapByColumnName(string $columnName): ColumnMap
    {
        $tableMap = $this->criteria->getTableMapOrFail();
        if (!$tableMap->hasColumnByPhpName($columnName)) {
            throw new UnknownColumnException('Unknown column ' . $columnName . ' in model ' . $tableMap->getName());
        }

        return $tableMap->getColumnByPhpName($columnName);
    }

    /**
     * Return a fully qualified column name corresponding to a simple column phpName
     * Uses model alias if it exists
     * Warning: restricted to the columns of the main model
     * e.g. => 'Title' => 'book.TITLE'
     *
     * @param string $columnName the Column phpName, without the table name
     *
     * @throws \Propel\Runtime\ActiveQuery\Exception\UnknownColumnException
     *
     * @return string the fully qualified column name
     */
    public function getRealColumnName(string $columnName): string
    {
        $tableMap = $this->criteria->getTableMapOrFail();
        if (!$tableMap->hasColumnByPhpName($columnName)) {
            throw new UnknownColumnException('Unknown column ' . $columnName . ' in model ' . $tableMap->getName());
        }
        $tableName = $this->criteria->getTableNameInQuery();
        $columnName = $tableMap->getColumnByPhpName($columnName)->getName();

        return "$tableName.$columnName";
    }

    /**
     * @deprecated use ModelCriteria::buildBindParams()
     *
     * Get all the parameters to bind to this criteria
     * Does part of the job of createSelectSql() for the cache
     *
     * @return array list of parameters, each parameter being an array like
     *               array('table' => $realtable, 'column' => $column, 'value' => $value)
     */
    public function getParams(): array
    {
        return $this->buildBindParams();
    }

    /**
     * Get all the parameters to bind to this criteria
     * Does part of the job of createSelectSql() for the cache
     *
     * @return array list of parameters, each parameter being an array like
     *               array('table' => $realtable, 'column' => $column, 'value' => $value)
     */
    public function buildBindParams(): array
    {
        $params = [];

        foreach ($this->criteria->filterCollector->getColumnFilters() as $filter) {
            $filter->collectParameters($params);
        }

        $having = $this->criteria->getHaving();
        if ($having !== null) {
            $having->collectParameters($params);
        }

        return $params;
    }

    /**
     * @deprecated just use ModelCriteria::addUsingOperator(), local columns will be resolved anyway.
     *
     * Overrides Criteria::add() to force the use of a true table alias if it exists
     *
     * @param string $qualifiedColumnName The colName of column to run the condition on (e.g. BookTableMap::ID)
     * @param mixed $value
     * @param string|null $operator A String, like Criteria::EQUAL.
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria
     */
    public function addUsingAlias(string $qualifiedColumnName, $value = null, ?string $operator = null)
    {
        return $this->criteria->addUsingOperator($qualifiedColumnName, $value, $operator);
    }
}
