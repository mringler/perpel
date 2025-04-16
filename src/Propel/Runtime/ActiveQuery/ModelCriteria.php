<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery;

use Exception;
use Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression;
use Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\LocalColumnExpression;
use Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\UnresolvedColumnExpression;
use Propel\Runtime\ActiveQuery\Criterion\ExistsQueryCriterion;
use Propel\Runtime\ActiveQuery\Exception\UnknownColumnException;
use Propel\Runtime\ActiveQuery\Exception\UnknownRelationException;
use Propel\Runtime\ActiveQuery\FilterExpression\ClauseList;
use Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface;
use Propel\Runtime\ActiveQuery\FilterExpression\ColumnToQueryFilter;
use Propel\Runtime\ActiveQuery\FilterExpression\ExistsFilter;
use Propel\Runtime\ActiveQuery\ModelCriteria as ActiveQueryModelCriteria;
use Propel\Runtime\Collection\ArrayCollection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\DataFetcher\DataFetcherInterface;
use Propel\Runtime\Exception\ClassNotFoundException;
use Propel\Runtime\Exception\LogicException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Exception\RuntimeException;
use Propel\Runtime\Exception\UnexpectedValueException;
use Propel\Runtime\Formatter\SimpleArrayFormatter;
use Propel\Runtime\Map\Exception\ColumnNotFoundException;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\Util\PropelModelPager;

/**
 * This class extends the Criteria by adding runtime introspection abilities
 * in order to ease the building of queries.
 *
 * A ModelCriteria requires additional information to be initialized.
 * Using a model name and tablemaps, a ModelCriteria can do more powerful things than a simple Criteria
 *
 * magic methods:
 *
 * @method \Propel\Runtime\ActiveQuery\ModelCriteria leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method \Propel\Runtime\ActiveQuery\ModelCriteria rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method \Propel\Runtime\ActiveQuery\ModelCriteria innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @author François Zaninotto
 */
class ModelCriteria extends BaseModelCriteria
{
    /**
     * @var string
     */
    public const FORMAT_STATEMENT = '\Propel\Runtime\Formatter\StatementFormatter';

    /**
     * @var string
     */
    public const FORMAT_ARRAY = '\Propel\Runtime\Formatter\ArrayFormatter';

    /**
     * @var string
     */
    public const FORMAT_OBJECT = '\Propel\Runtime\Formatter\ObjectFormatter';

    /**
     * @var string
     */
    public const FORMAT_ON_DEMAND = '\Propel\Runtime\Formatter\OnDemandFormatter';

    /**
     * Parent query (i.e. this is a useQuery)
     *
     * @var \Propel\Runtime\ActiveQuery\ModelCriteria|null
     */
    protected $primaryCriteria;

    /**
     * @var string|null
     */
    protected $entityNotFoundExceptionClass;

    /**
     * This is introduced to prevent useQuery->join from going wrong
     *
     * @var \Propel\Runtime\ActiveQuery\Join|null
     */
    protected $previousJoin;

    /**
     * Whether to clone the current object before termination methods
     *
     * @var bool
     */
    protected $isKeepQuery = true;

    /**
     * User-selected columns.
     *
     * Set in {@see static::select()}. Will be added as AS columns in {@see static::configureSelectColumns()}
     *
     * @var array<string>|null
     */
    protected $select;

    /**
     * Used to memorize whether we added self-select columns before.
     *
     * @var bool
     */
    protected $isSelfSelected = false;

    /**
     * Indicates that this query is wrapped in an InnerQueryCriterion.
     *
     * Marks the query to be
     *
     * @see ModelCriteria::useAbstractInnerQueryCriterion()
     * @see ModelCriteria::endUse()
     *
     * @var bool
     */
    protected $isInnerQueryInCriterion = false;

    /**
     * Adds a condition on a column based on a column phpName and a value
     * Uses introspection to translate the column phpName into a fully qualified name
     * Warning: recognizes only the phpNames of the main Model (not joined tables)
     * <code>
     * $c->filterBy('Title', 'foo');
     * </code>
     *
     * @param string $columnPhpName A string representing thecolumn phpName, e.g. 'AuthorId'
     * @param mixed $value A value for the condition
     * @param string|null $comparison What to use for the column comparison, defaults to Criteria::EQUAL or Criteria::IN for subqueries
     *
     * @return static
     */
    public function filterBy(string $columnPhpName, $value, ?string $comparison = null)
    {
        $resolvedColumn = $this->resolveLocalColumnByName($columnPhpName, true);

        return $this->addUsingOperator($resolvedColumn, $value, $comparison);
    }

    /**
     * Adds a list of conditions on the columns of the current model
     * Uses introspection to translate the column phpName into a fully qualified name
     * Warning: recognizes only the phpNames of the main Model (not joined tables)
     * <code>
     * $c->filterByArray(array(
     *  'Title' => 'War And Peace',
     *  'Publisher' => $publisher
     * ));
     * </code>
     *
     * @see filterBy()
     *
     * @param mixed $conditions An array of conditions, using column phpNames as key
     *
     * @return $this
     */
    public function filterByArray($conditions)
    {
        foreach ($conditions as $column => $args) {
            if (!is_array($args)) {
                $args = [$args];
            }
            $this->{"filterBy$column"}(...$args);
        }

        return $this;
    }

    /**
     * Adds a condition on a column based on a pseudo SQL clause
     * Uses introspection to translate the column phpName into a fully qualified name
     * <code>
     * // simple clause
     * $c->where('b.Title = ?', 'foo');
     * // named conditions
     * $c->condition('cond1', 'b.Title = ?', 'foo');
     * $c->condition('cond2', 'b.ISBN = ?', 12345);
     * $c->where(array('cond1', 'cond2'), Criteria::LOGICAL_OR);
     * </code>
     *
     * @phpstan-param literal-string|array $clause
     *
     * @psalm-param literal-string|array $clause
     *
     * @param array<string>|string $clause A string representing the pseudo SQL clause, e.g. 'Book.AuthorId = ?'
     *   Or an array of condition names
     * @param mixed $value A value for the condition
     * @param int|null $bindingType
     *
     * @return static
     */
    public function where($clause, $value = null, ?int $bindingType = null)
    {
        if (is_array($clause)) {
            // where(array('cond1', 'cond2'), Criteria::LOGICAL_OR)
            $criterion = $this->getDeprecatedMethods()->getCriterionForConditions($clause, $value);
        } else {
            // where('Book.AuthorId = ?', 12)
            $criterion = $this->buildFilterForClause($clause, $value, $bindingType);
        }

        return $this->addUsingOperator($criterion, null, null);
    }

    /**
     * Adds an EXISTS clause with a custom query object.
     *
     * Note that filter conditions linking data from the outer query with data from the inner
     * query are not inferred and have to be added manually. If a relationship exists between
     * outer and inner table, {@link ModelCriteria::useExistsQuery()} can be used to infer filter
     * automatically..
     *
     * @example MyOuterQuery::create()->whereExists(MyDataQuery::create()->where('MyData.MyField = MyOuter.MyField'))
     *
     * @phpstan-param \Propel\Runtime\ActiveQuery\Criterion\ExistsQueryCriterion::TYPE_* $operator
     *
     * @see ModelCriteria::useExistsQuery() can be used
     *
     * @param \Propel\Runtime\ActiveQuery\ModelCriteria $existsQueryCriteria the query object used in the EXISTS statement
     * @param string $operator Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS. Defaults to EXISTS
     *
     * @return static
     */
    public function whereExists(ActiveQueryModelCriteria $existsQueryCriteria, string $operator = ExistsQueryCriterion::TYPE_EXISTS)
    {
        $criterion = new ExistsQueryCriterion($this, null, $operator, $existsQueryCriteria);

        return $this->addUsingOperator($criterion);
    }

    /**
     * Negation of {@link ModelCriteria::whereExists()}
     *
     * @param \Propel\Runtime\ActiveQuery\ModelCriteria $existsQueryCriteria
     *
     * @return static
     */
    public function whereNotExists(ActiveQueryModelCriteria $existsQueryCriteria)
    {
        return $this->whereExists($existsQueryCriteria, ExistsQueryCriterion::TYPE_NOT_EXISTS);
    }

    /**
     * Adds a having condition on a column based on a pseudo SQL clause
     * Uses introspection to translate the column phpName into a fully qualified name
     * <code>
     * // simple clause
     * $c->having('b.Title = ?', 'foo');
     * // named conditions
     * $c->condition('cond1', 'b.Title = ?', 'foo');
     * $c->condition('cond2', 'b.ISBN = ?', 12345);
     * $c->having(array('cond1', 'cond2'), Criteria::LOGICAL_OR);
     * </code>
     *
     * @see Criteria::addHaving()
     *
     * @param array<string>|string $clause A string representing the pseudo SQL clause, e.g. 'Book.AuthorId = ?'
     *                      Or an array of condition names
     * @param mixed $value A value for the condition
     * @param int|null $bindingType
     *
     * @return static
     */
    public function having($clause, $value = null, ?int $bindingType = null)
    {
        if (is_array($clause)) {
            // having(array('cond1', 'cond2'), Criteria::LOGICAL_OR)
            $criterion = $this->getDeprecatedMethods()->getCriterionForConditions($clause, $value);
        } else {
            // having('Book.AuthorId = ?', 12)
            $criterion = $this->buildFilterForClause($clause, $value, $bindingType);
        }

        return $this->addHaving($criterion);
    }

    /**
     * Adds an ORDER BY clause to the query
     * Usability layer on top of Criteria::addAscendingOrderByColumn() and Criteria::addDescendingOrderByColumn()
     * Infers $column and $order from $columnName and some optional arguments
     * Examples:
     *   $c->orderBy('Book.CreatedAt')
     *    => $c->addAscendingOrderByColumn(BookTableMap::CREATED_AT)
     *   $c->orderBy('Book.CategoryId', 'desc')
     *    => $c->addDescendingOrderByColumn(BookTableMap::CATEGORY_ID)
     *
     * @param string $columnName The column to order by
     * @param string $order The sorting order. Criteria::ASC by default, also accepts Criteria::DESC
     *
     * @throws \Propel\Runtime\Exception\UnexpectedValueException
     *
     * @return static
     */
    public function orderBy(string $columnName, string $order = Criteria::ASC)
    {
        $resolvedColumn = $this->columnResolver->resolveColumn($columnName, true, false);
        $qualifiedColumnName = $resolvedColumn->getColumnExpressionInQuery();

        $order = strtoupper($order);
        switch ($order) {
            case Criteria::ASC:
                return $this->addAscendingOrderByColumn($qualifiedColumnName);
            case Criteria::DESC:
                return $this->addDescendingOrderByColumn($qualifiedColumnName);
            default:
                throw new UnexpectedValueException('ModelCriteria::orderBy() only accepts Criteria::ASC or Criteria::DESC as argument');
        }
    }

    /**
     * Adds a GROUP BY clause to the query
     * Usability layer on top of Criteria::addGroupByColumn()
     * Infers $column $columnName
     * Examples:
     *   $c->groupBy('Book.AuthorId')
     *    => $c->addGroupByColumn(BookTableMap::AUTHOR_ID)
     *
     *   $c->groupBy(array('Book.AuthorId', 'Book.AuthorName'))
     *    => $c->addGroupByColumn(BookTableMap::AUTHOR_ID)
     *    => $c->addGroupByColumn(BookTableMap::AUTHOR_NAME)
     *
     * @param mixed $columnNames an array of columns name (e.g. array('Book.AuthorId', 'Book.AuthorName')) or a single column name (e.g. 'Book.AuthorId')
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this
     */
    public function groupBy($columnNames)
    {
        if (!$columnNames) {
            throw new PropelException('You must ask for at least one column');
        }

        foreach ((array)$columnNames as $columnName) {
            $localColumnName = $this->columnResolver->resolveColumn($columnName, true, false)->getColumnExpressionInQuery();
            $this->addGroupByColumn($localColumnName);
        }

        return $this;
    }

    /**
     * Adds a GROUP BY clause for all columns of a model to the query
     * Examples:
     *   $c->groupBy('Book');
     *    => $c->addGroupByColumn(BookTableMap::ID);
     *    => $c->addGroupByColumn(BookTableMap::TITLE);
     *    => $c->addGroupByColumn(BookTableMap::AUTHOR_ID);
     *    => $c->addGroupByColumn(BookTableMap::PUBLISHER_ID);
     *
     * @param string $class The class name or alias
     *
     * @throws \Propel\Runtime\Exception\ClassNotFoundException
     *
     * @return $this
     */
    public function groupByClass(string $class)
    {
        if ($class == $this->getModelAliasOrName()) {
            // column of the Criteria's model
            $tableMap = $this->getTableMap();
        } elseif (isset($this->joins[$class])) {
            // column of a relations's model
            $tableMap = $this->joins[$class]->getTableMap();
        } else {
            throw new ClassNotFoundException(sprintf('Unknown model or alias: %s.', $class));
        }

        foreach ($tableMap->getColumns() as $column) {
            if (isset($this->aliases[$class])) {
                $this->addGroupByColumn($class . '.' . $column->getName());
            } else {
                $this->addGroupByColumn($column->getFullyQualifiedName());
            }
        }

        return $this;
    }

    /**
     * Makes the ModelCriteria return a string, array, or ArrayCollection
     * Examples:
     *   ArticleQuery::create()->select('Name')->find();
     *   => ArrayCollection Object ('Foo', 'Bar')
     *
     *   ArticleQuery::create()->select('Name')->findOne();
     *   => string 'Foo'
     *
     *   ArticleQuery::create()->select(array('Id', 'Name'))->find();
     *   => ArrayCollection Object (
     *        array('Id' => 1, 'Name' => 'Foo'),
     *        array('Id' => 2, 'Name' => 'Bar')
     *      )
     *
     *   ArticleQuery::create()->select(array('Id', 'Name'))->findOne();
     *   => array('Id' => 1, 'Name' => 'Foo')
     *
     * @param mixed $columnArray A list of column names (e.g. array('Title', 'Category.Name', 'c.Content')) or a single column name (e.g. 'Name')
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this
     */
    public function select($columnArray)
    {
        if (!$columnArray) {
            throw new PropelException('You must ask for at least one column');
        }

        if ($columnArray === '*') {
            $columnArray = $this->resolveSelectAll();
        }
        if (!is_array($columnArray)) {
            $columnArray = [$columnArray];
        }
        $this->select = $columnArray;
        $this->isSelfSelected = true;

        return $this;
    }

    /**
     * @return list<string>
     */
    protected function resolveSelectAll(): array
    {
        $columnArray = [];
        foreach ($this->getTableMapOrFail()->getColumns() as $columnMap) {
            $columnArray[] = $this->modelName . '.' . $columnMap->getPhpName();
        }

        return $columnArray;
    }

    /**
     * Retrieves the columns defined by a previous call to select().
     *
     * @see select()
     *
     * @return array<string>|null A list of column names (e.g. array('Title', 'Category.Name', 'c.Content')) or a single column name (e.g. 'Name')
     */
    public function getSelect()
    {
        return $this->select;
    }

    /**
     * Whether this Criteria has any select columns.
     *
     * This will include columns added with addAsColumn() method.
     *
     * @see addAsColumn()
     * @see addSelectColumn()
     *
     * @return bool
     */
    public function hasSelectClause(): bool
    {
        return (bool)$this->select || parent::hasSelectClause();
    }

    /**
     * This method returns the previousJoin for this ModelCriteria,
     * by default this is null, but after useQuery this is set the to the join of that use
     *
     * @return \Propel\Runtime\ActiveQuery\Join|null the previousJoin for this ModelCriteria
     */
    public function getPreviousJoin(): ?Join
    {
        return $this->previousJoin;
    }

    /**
     * This method sets the previousJoin for this ModelCriteria,
     * by default this is null, but after useQuery this is set the to the join of that use
     *
     * @param \Propel\Runtime\ActiveQuery\Join $previousJoin The previousJoin for this ModelCriteria
     *
     * @return $this
     */
    public function setPreviousJoin(Join $previousJoin)
    {
        $this->previousJoin = $previousJoin;

        return $this;
    }

    /**
     * Adds a JOIN clause to the query
     * Infers the ON clause from a relation name
     * Uses the Propel table maps, based on the schema, to guess the related columns
     * Beware that the default JOIN operator is INNER JOIN, while Criteria defaults to WHERE
     * Examples:
     * <code>
     *   $c->join('Book.Author');
     *    => $c->addJoin(BookTableMap::AUTHOR_ID, AuthorTableMap::ID, Criteria::INNER_JOIN);
     *   $c->join('Book.Author', Criteria::RIGHT_JOIN);
     *    => $c->addJoin(BookTableMap::AUTHOR_ID, AuthorTableMap::ID, Criteria::RIGHT_JOIN);
     *   $c->join('Book.Author a', Criteria::RIGHT_JOIN);
     *    => $c->addAlias('a', AuthorTableMap::TABLE_NAME);
     *    => $c->addJoin(BookTableMap::AUTHOR_ID, 'a.ID', Criteria::RIGHT_JOIN);
     * </code>
     *
     * @param string $relation Relation to use for the join
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Propel\Runtime\ActiveQuery\Exception\UnknownRelationException
     *
     * @return $this
     */
    public function join(string $relation, string $joinType = Criteria::INNER_JOIN)
    {
        // relation looks like '$leftName.$relationName $relationAlias'
        [$fullName, $relationAlias] = self::getClassAndAlias($relation);
        if (strpos($fullName, '.') === false) {
            // simple relation name, refers to the current table
            $leftName = $this->getModelAliasOrName();
            $relationName = $fullName;
            $previousJoin = $this->getPreviousJoin();
            $tableMap = $this->getTableMap();
        } else {
            [$leftName, $relationName] = explode('.', $fullName);
            $shortLeftName = static::getShortName($leftName);
            // find the TableMap for the left table using the $leftName
            if ($leftName === $this->getModelAliasOrName() || $leftName === $this->getModelShortName()) {
                $previousJoin = $this->getPreviousJoin();
                $tableMap = $this->getTableMap();
            } elseif (isset($this->joins[$leftName])) {
                $previousJoin = $this->joins[$leftName];
                $tableMap = $previousJoin->getTableMap();
            } elseif (isset($this->joins[$shortLeftName])) {
                $previousJoin = $this->joins[$shortLeftName];
                $tableMap = $previousJoin->getTableMap();
            } else {
                throw new PropelException('Unknown table or alias ' . $leftName);
            }
        }
        $leftTableAlias = isset($this->aliases[$leftName]) ? $leftName : null;

        // find the RelationMap in the TableMap using the $relationName
        if (!$tableMap->hasRelation($relationName)) {
            throw new UnknownRelationException(sprintf('Unknown relation %s on the %s table.', $relationName, $leftName));
        }
        $relationMap = $tableMap->getRelation($relationName);

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        if ($previousJoin !== null) {
            $join->setPreviousJoin($previousJoin);
        }
        $join->setRelationMap($relationMap, $leftTableAlias, $relationAlias);

        // add the ModelJoin to the current object
        if ($relationAlias !== null) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, $relationName);
        }

        return $this;
    }

    /**
     * Add another condition to an already added join
     *
     * @example
     * <code>
     * $query->join('Book.Author');
     * $query->addJoinCondition('Author', 'Book.Title LIKE ?', 'foo%');
     * </code>
     *
     * @param string $name The relation name or alias on which the join was created
     * @param string $clause SQL clause, may contain column and table phpNames
     * @param mixed $value An optional value to bind to the clause
     * @param string|null $operator The operator to use to add the condition. Defaults to 'AND'
     * @param int|null $bindingType
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this
     */
    public function addJoinCondition(string $name, string $clause, $value = null, ?string $operator = null, ?int $bindingType = null)
    {
        if (!isset($this->joins[$name])) {
            throw new PropelException(sprintf('Adding a condition to a nonexistent join, %s. Try calling join() first.', $name));
        }
        $join = $this->joins[$name];
        if (!$join->getJoinCondition() instanceof ColumnFilterInterface) {
            $join->buildJoinCondition($this);
        }
        $filter = $this->buildFilterForClause($clause, $value, $bindingType);
        $join->getJoinConditionOrFail()->addFilter($filter, $operator ?: ClauseList::AND_OPERATOR_LITERAL);

        return $this;
    }

    /**
     * Replace the condition of an already added join
     *
     * @example
     * <code>
     * $query->join('Book.Author');
     * $query->condition('cond1', 'Book.AuthorId = Author.Id')
     * $query->condition('cond2', 'Book.Title LIKE ?', 'War%')
     * $query->combine(array('cond1', 'cond2'), 'and', 'cond3')
     * $query->setJoinCondition('Author', 'cond3');
     * </code>
     *
     * @param string $name The relation name or alias on which the join was created
     * @param \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface|string $condition A Criterion object, or a condition name
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this
     */
    public function setJoinCondition(string $name, $condition)
    {
        if (!isset($this->joins[$name])) {
            throw new PropelException(sprintf('Setting a condition to a nonexistent join, %s. Try calling join() first.', $name));
        }

        if ($condition instanceof ColumnFilterInterface) {
            $this->getJoin($name)->setJoinCondition($condition);
        } elseif ($this->getDeprecatedMethods()->hasCond($condition)) {
            $this->getJoin($name)->setJoinCondition($this->getDeprecatedMethods()->getCond($condition));
        } else {
            throw new PropelException(sprintf('Cannot add condition %s on join %s. setJoinCondition() expects either a Criterion, or a condition added by way of condition()', $condition, $name));
        }

        return $this;
    }

    /**
     * Register a join object in the Criteria
     *
     * @see Criteria::addJoinObject()
     *
     * @param \Propel\Runtime\ActiveQuery\Join $join A join object
     * @param string|null $name
     *
     * @return $this
     */
    public function addJoinObject(Join $join, ?string $name = null)
    {
        if (!in_array($join, $this->joins)) { // compare equality, NOT identity
            if ($name === null) {
                $this->joins[] = $join;
            } else {
                $this->joins[$name] = $join;
            }
        }

        return $this;
    }

    /**
     * Adds a JOIN clause to the query and hydrates the related objects
     * Shortcut for $c->join()->with()
     * <code>
     *   $c->joinWith('Book.Author');
     *    => $c->join('Book.Author');
     *    => $c->with('Author');
     *   $c->joinWith('Book.Author a', Criteria::RIGHT_JOIN);
     *    => $c->join('Book.Author a', Criteria::RIGHT_JOIN);
     *    => $c->with('a');
     * </code>
     *
     * @param string $relation Relation to use for the join
     * @param string|null $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this
     */
    public function joinWith(string $relation, ?string $joinType = null)
    {
        if ($joinType === null) {
            $joinType = Criteria::INNER_JOIN;
        }

        $this->join($relation, $joinType);
        $this->with(self::getRelationName($relation));

        return $this;
    }

    /**
     * Adds a relation to hydrate together with the main object
     * The relation must be initialized via a join() prior to calling with()
     * Examples:
     * <code>
     *   $c->join('Book.Author');
     *   $c->with('Author');
     *
     *   $c->join('Book.Author a', Criteria::RIGHT_JOIN);
     *   $c->with('a');
     * </code>
     * WARNING: on a one-to-many relationship, the use of with() combined with limit()
     * will return a wrong number of results for the related objects
     *
     * @param string $relation Relation to use for the join
     *
     * @throws \Propel\Runtime\ActiveQuery\Exception\UnknownRelationException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return $this
     */
    public function with(string $relation)
    {
        if (!isset($this->joins[$relation])) {
            throw new UnknownRelationException('Unknown relation name or alias ' . $relation);
        }

        /** @var \Propel\Runtime\ActiveQuery\ModelJoin $join */
        $join = $this->joins[$relation];
        $relationMap = $join->getRelationMap();
        if ($relationMap && $relationMap->getType() === RelationMap::MANY_TO_MANY) {
            throw new PropelException(__METHOD__ . ' does not allow hydration for many-to-many relationships');
        }

        // check that the columns of the main class are already added (but only if this isn't a useQuery)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectColumns();
        }
        // add the columns of the related class
        $this->addRelationSelectColumns($relation);

        // list the join for later hydration in the formatter
        $this->with[$relation] = new ModelWith($join);

        return $this;
    }

    /**
     * @deprecated use addAsColumn() - same effect, no side-effects.
     *
     * Adds a supplementary column to the select clause
     * These columns can later be retrieved from the hydrated objects using getVirtualColumn()
     *
     * @param string $clause The SQL clause with object model column names
     *                       e.g. 'UPPER(Author.FirstName)'
     * @param string|null $name Optional alias for the added column
     *                       If no alias is provided, the clause is used as a column alias
     *                       This alias is used for retrieving the column via BaseObject::getVirtualColumn($alias)
     *
     * @return $this
     */
    public function withColumn(string $clause, ?string $name = null)
    {
        if ($name === null) {
            $name = str_replace(['.', '(', ')'], '', $clause);
        }

        $clause = $this->normalizeFilterExpression($clause)->getNormalizedFilterExpression();
        // check that the columns of the main class are already added (if this is the primary ModelCriteria)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectColumns();
        }
        $this->addAsColumn($name, $clause);

        return $this;
    }

    /**
     * Initializes a secondary ModelCriteria object, to be later merged with the current object
     *
     * @psalm-param class-string<self>|null $secondaryCriteriaClass
     *
     * @see ModelCriteria::endUse()
     *
     * @param string $relationName Relation name or alias
     * @param string|null $secondaryCriteriaClass ClassName for the ModelCriteria to be used
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return self The secondary criteria object
     */
    public function useQuery(string $relationName, ?string $secondaryCriteriaClass = null): self
    {
        if (!isset($this->joins[$relationName])) {
            throw new PropelException('Unknown class or alias ' . $relationName);
        }

        /** @var \Propel\Runtime\ActiveQuery\ModelJoin $modelJoin */
        $modelJoin = $this->joins[$relationName];
        $className = $modelJoin->getTableMap() ? (string)$modelJoin->getTableMap()->getClassName() : '';
        if ($secondaryCriteriaClass === null) {
            $secondaryCriteria = PropelQuery::from($className);
        } else {
            $secondaryCriteria = new $secondaryCriteriaClass();
        }

        if ($className !== $relationName) {
            $modelName = $modelJoin->getRelationMap() ? $modelJoin->getRelationMap()->getName() : '';
            $secondaryCriteria->setModelAlias($relationName, !($relationName == $modelName));
        }

        $secondaryCriteria->setPrimaryCriteria($this, $modelJoin);

        return $secondaryCriteria;
    }

    /**
     * Finalizes a secondary criteria and merges it with its primary Criteria
     *
     * @see Criteria::mergeWith()
     *
     * @throws \Propel\Runtime\Exception\RuntimeException
     *
     * @return self|null The primary criteria object
     */
    public function endUse(): ?self
    {
        if ($this->isInnerQueryInCriterion) {
            return $this->getPrimaryCriteria();
        }

        if (isset($this->aliases[$this->modelAlias])) {
            $this->removeAlias((string)$this->modelAlias);
        }

        $primaryCriteria = $this->getPrimaryCriteria();
        if ($primaryCriteria === null) {
            throw new RuntimeException('No primary criteria');
        }

        $primaryCriteria->mergeWith($this);

        return $primaryCriteria;
    }

    /**
     * Adds and returns an internal query to be used in an EXISTS-clause.
     *
     * @param class-string<\Propel\Runtime\ActiveQuery\FilterExpression\AbstractInnerQueryFilter> $abstractInnerQueryCriterionClass
     * @param string $relationName name of the relation
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<\Propel\Runtime\ActiveQuery\ModelCriteria>|null $queryClass allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string|null $operatorDeclaration Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS. Defaults to EXISTS
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria
     */
    protected function useAbstractInnerQueryCriterion(
        string $abstractInnerQueryCriterionClass,
        string $relationName,
        ?string $modelAlias = null,
        ?string $queryClass = null,
        ?string $operatorDeclaration = null
    ) {
        $relationMap = $this->getTableMapOrFail()->getRelation($relationName);
        $className = (string)$relationMap->getRightTable()->getClassName();

        /** @var static $innerQuery */
        $innerQuery = ($queryClass === null) ? PropelQuery::from($className) : new $queryClass();
        $innerQuery->isInnerQueryInCriterion = true;
        $innerQuery->primaryCriteria = $this;
        if ($modelAlias !== null) {
            $innerQuery->setModelAlias($modelAlias, true);
        }

        $criterion = $abstractInnerQueryCriterionClass::createForRelation($this, $relationMap, $operatorDeclaration, $innerQuery);
        $this->addUsingOperator($criterion);

        return $innerQuery;
    }

    /**
     * Adds and returns an internal query to be used in an EXISTS-clause.
     *
     * @phpstan-param \Propel\Runtime\ActiveQuery\Criterion\ExistsQueryCriterion::TYPE_* $type
     *
     * @param string $relationName name of the relation
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<\Propel\Runtime\ActiveQuery\ModelCriteria>|null $queryClass allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $type Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS. Defaults to EXISTS
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria
     */
    public function useExistsQuery(
        string $relationName,
        ?string $modelAlias = null,
        ?string $queryClass = null,
        string $type = ExistsFilter::TYPE_EXISTS
    ) {
        return $this->useAbstractInnerQueryCriterion(ExistsFilter::class, $relationName, $modelAlias, $queryClass, $type);
    }

    /**
     * Use NOT EXISTS rather than EXISTS.
     *
     * @see ModelCriteria::useExistsQuery()
     *
     * @param string $relationName
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<\Propel\Runtime\ActiveQuery\ModelCriteria>|null $queryClass allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria
     */
    public function useNotExistsQuery(string $relationName, ?string $modelAlias = null, ?string $queryClass = null)
    {
        return $this->useExistsQuery($relationName, $modelAlias, $queryClass, ExistsQueryCriterion::TYPE_NOT_EXISTS);
    }

    /**
     * Adds and returns an internal query to be used in an IN-clause.
     *
     * @phpstan-param \Propel\Runtime\ActiveQuery\Criteria::*IN $type
     *
     * @param string $relationName name of the relation
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<\Propel\Runtime\ActiveQuery\ModelCriteria>|null $queryClass allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $type Criteria::IN or Criteria::NOT_IN. Defaults to IN
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria
     */
    public function useInQuery(
        string $relationName,
        ?string $modelAlias = null,
        ?string $queryClass = null,
        string $type = Criteria::IN
    ) {
        return $this->useAbstractInnerQueryCriterion(ColumnToQueryFilter::class, $relationName, $modelAlias, $queryClass, $type);
    }

    /**
     * Use NOT IN rather than IN.
     *
     * @see ModelCriteria::useExistsQuery()
     *
     * @param string $relationName
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<\Propel\Runtime\ActiveQuery\ModelCriteria>|null $queryClass allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria
     */
    public function useNotInQuery(string $relationName, ?string $modelAlias = null, ?string $queryClass = null)
    {
        return $this->useInQuery($relationName, $modelAlias, $queryClass, Criteria::NOT_IN);
    }

    /**
     * Add the content of a Criteria to the current Criteria
     * In case of conflict, the current Criteria keeps its properties
     *
     * @see Criteria::mergeWith()
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria The criteria to read properties from
     * @param string|null $operator The logical operator used to combine conditions
     *                           Defaults to Criteria::LOGICAL_AND, also accepts Criteria::LOGICAL_OR
     *
     * @return $this The primary criteria object
     */
    public function mergeWith(Criteria $criteria, ?string $operator = null)
    {
        if (
            $criteria instanceof ModelCriteria
            && !$criteria->getPrimaryCriteria()
            && $criteria->isSelfColumnsSelected()
            && $criteria->getWith()
        ) {
            if (!$this->isSelfColumnsSelected()) {
                $this->addSelfSelectColumns();
            }
            $criteria->removeSelfSelectColumns();
        }

        parent::mergeWith($criteria, $operator);

        // merge with
        if ($criteria instanceof ModelCriteria) {
            $this->with = array_merge($this->getWith(), $criteria->getWith());
        }

        return $this;
    }

    /**
     * Clear the conditions to allow the reuse of the query object.
     * The ModelCriteria's Model and alias 'all the properties set by construct) will remain.
     *
     * @return $this
     */
    public function clear()
    {
        parent::clear();

        $this->with = [];
        $this->primaryCriteria = null;
        $this->formatter = null;
        $this->select = null;
        $this->isSelfSelected = false;

        return $this;
    }

    /**
     * Sets the primary Criteria for this secondary Criteria
     *
     * @param \Propel\Runtime\ActiveQuery\ModelCriteria $criteria The primary criteria
     * @param \Propel\Runtime\ActiveQuery\Join|null $previousJoin The previousJoin for this ModelCriteria
     *
     * @return static
     */
    public function setPrimaryCriteria(ModelCriteria $criteria, ?Join $previousJoin)
    {
        $this->primaryCriteria = $criteria;
        if ($previousJoin) {
            $this->setPreviousJoin($previousJoin);
        }

        return $this;
    }

    /**
     * Gets the primary criteria for this secondary Criteria
     *
     * @return \Propel\Runtime\ActiveQuery\ModelCriteria|null The primary criteria
     */
    public function getPrimaryCriteria(): ?self
    {
        return $this->primaryCriteria;
    }

    /**
     * Adds a Criteria as subQuery in the From Clause.
     *
     * @see Criteria::addSubquery()
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria $subQuery Criteria to build the subquery from
     * @param string|null $alias alias for the subQuery
     * @param bool $addAliasAndSelectColumns Set to false if you want to manually add the aliased select columns
     *
     * @return $this
     */
    public function addSubquery(Criteria $subQuery, ?string $alias = null, bool $addAliasAndSelectColumns = true)
    {
        if (!$subQuery->hasSelectClause()) {
            $subQuery->addSelfSelectColumns();
        }

        parent::addSubquery($subQuery, $alias);
        if ($subQuery instanceof BaseModelCriteria && $subQuery->modelAlias) {
            $subQuery->useAliasInSQL = true;
        }

        if (!$addAliasAndSelectColumns || !$subQuery instanceof BaseModelCriteria) {
            return $this;
        }

        if ($alias === null) {
            // get the default alias set in parent::addSubquery()
            end($this->selectQueries);
            $alias = (string)key($this->selectQueries);
        }

        if ($subQuery->modelTableMapName === $this->modelTableMapName) {
            $this->setModelAlias($alias, true);
            $this->addSelfSelectColumns(true);
        } else {
            $tableMapClassName = (string)$subQuery->modelTableMapName;
            $this->addSelfSelectColumnsFromTableMapClass($tableMapClassName, $alias);
        }

        return $this;
    }

   /**
    * @deprecated use aptly named Criteria::addSubquery().
    *
    * @param \Propel\Runtime\ActiveQuery\Criteria $subQueryCriteria Criteria to build the subquery from
    * @param string|null $alias alias for the subQuery
    * @param bool $addAliasAndSelectColumns Set to false if you want to manually add the aliased select columns
    *
    * @return static
    */
    public function addSelectQuery(Criteria $subQueryCriteria, ?string $alias = null, bool $addAliasAndSelectColumns = true)
    {
        return $this->addSubquery($subQueryCriteria, $alias, $addAliasAndSelectColumns);
    }

    /**
     * Adds the select columns for the current table
     *
     * @param bool $force To enforce adding columns for changed alias, set it to true (f.e. with sub selects)
     *
     * @return $this
     */
    public function addSelfSelectColumns(bool $force = false)
    {
        if ($this->isSelfSelected && !$force) {
            return $this;
        }

        /** @var string $tableMapClassName */
        $tableMapClassName = $this->modelTableMapName;
        $alias = ($this->useAliasInSQL) ? $this->modelAlias : null;

        $this->addSelfSelectColumnsFromTableMapClass($tableMapClassName, $alias);

        return $this;
    }

    /**
     * Adds the select columns for the given table.
     *
     * @param string $tableMapClassName
     * @param string|null $alias
     *
     * @return $this
     */
    public function addSelfSelectColumnsFromTableMapClass(string $tableMapClassName, ?string $alias = null)
    {
        $tableMapClassName::addSelectColumns($this, $alias);
        $this->isSelfSelected = true;

        return $this;
    }

    /**
     * Removes the select columns for the current table
     *
     * @param bool $force To enforce removing columns for changed alias, set it to true (f.e. with sub selects)
     *
     * @return $this
     */
    public function removeSelfSelectColumns(bool $force = false)
    {
        if (!$this->isSelfSelected && !$force) {
            return $this;
        }

        /** @var string $tableMap */
        $tableMap = $this->modelTableMapName;
        $tableMap::removeSelectColumns($this, $this->useAliasInSQL ? $this->modelAlias : null);
        $this->isSelfSelected = false;

        return $this;
    }

    /**
     * Returns whether select columns for the current table are included
     *
     * @return bool
     */
    public function isSelfColumnsSelected(): bool
    {
        return $this->isSelfSelected;
    }

    /**
     * Adds the select columns for a relation
     *
     * @param string $relation The relation name or alias, as defined in join()
     *
     * @return $this
     */
    public function addRelationSelectColumns(string $relation)
    {
        /** @var \Propel\Runtime\ActiveQuery\ModelJoin $join */
        $join = $this->joins[$relation];
        if ($join->getTableMap()) {
            $join->getTableMap()->addSelectColumns($this, $join->getRelationAlias());
        }

        return $this;
    }

    /**
     * Returns the class and alias of a string representing a model or a relation
     * e.g. 'Book b' => array('Book', 'b')
     * e.g. 'Book' => array('Book', null)
     *
     * @param string $class The classname to explode
     *
     * @return array list($className, $aliasName)
     */
    public static function getClassAndAlias(string $class): array
    {
        if (strpos($class, ' ') !== false) {
            [$class, $alias] = explode(' ', $class);
        } else {
            $alias = null;
        }
        if (strpos($class, '\\') === 0) {
            $class = substr($class, 1);
        }

        return [$class, $alias];
    }

    /**
     * Returns the name of a relation from a string.
     * The input looks like '$leftName.$relationName $relationAlias'
     *
     * @param string $relation Relation to use for the join
     *
     * @return string the relationName used in the join
     */
    public static function getRelationName(string $relation): string
    {
        // get the relationName
        [$fullName, $relationAlias] = self::getClassAndAlias($relation);
        if ($relationAlias) {
            $relationName = $relationAlias;
        } elseif (strpos($fullName, '.') === false) {
            $relationName = $fullName;
        } else {
            [, $relationName] = explode('.', $fullName);
        }

        return $relationName;
    }

    /**
     * Triggers the automated cloning on termination.
     * By default, termination methods don't clone the current object,
     * even though they modify it. If the query must be reused after termination,
     * you must call this method prior to termination.
     *
     * @param bool $isKeepQuery
     *
     * @return $this
     */
    public function keepQuery(bool $isKeepQuery = true)
    {
        $this->isKeepQuery = $isKeepQuery;

        return $this;
    }

    /**
     * Checks whether the automated cloning on termination is enabled.
     *
     * @return bool true if cloning must be done before termination
     */
    public function isKeepQuery(): bool
    {
        return $this->isKeepQuery;
    }

    /**
     * Code to execute before every SELECT statement
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface $con The connection object used by the query
     *
     * @return void
     */
    protected function basePreSelect(ConnectionInterface $con): void
    {
        $this->preSelect($con);
    }

    /**
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     *
     * @return void
     */
    protected function preSelect(ConnectionInterface $con): void
    {
    }

    /**
     * Issue a SELECT query based on the current ModelCriteria
     * and format the list of results with the current formatter
     * By default, returns an array of model objects
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return \Propel\Runtime\Collection\Collection<\Propel\Runtime\ActiveRecord\ActiveRecordInterface>|mixed the list of results, formatted by the current formatter
     */
    public function find(?ConnectionInterface $con = null)
    {
        $criteria = $this->isKeepQuery() ? (clone $this)->keepQuery(false) : $this;
        $dataFetcher = $criteria->fetch($con);

        return $criteria->getFormatter()->init($criteria)->format($dataFetcher);
    }

    /**
     * Same as find(), but returns a typed ObjectCollection.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con
     *
     * @return \Propel\Runtime\Collection\ObjectCollection<\Propel\Runtime\ActiveRecord\ActiveRecordInterface>
     */
    public function findObjects(?ConnectionInterface $con = null): ObjectCollection
    {
        $this->setFormatter(static::FORMAT_OBJECT);

        return $this->find($con);
    }

    /**
     * Same as find(), but returns a (typed) ArrayCollection.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con
     *
     * @return \Propel\Runtime\Collection\ArrayCollection
     */
    public function findTuples(?ConnectionInterface $con = null): ArrayCollection
    {
        $this->setFormatter(static::FORMAT_ARRAY);

        return $this->find($con);
    }

    /**
     * Issue a SELECT ... LIMIT 1 query based on the current ModelCriteria
     * and format the result with the current formatter
     * By default, returns a model object.
     *
     * Does not work with ->with()s containing one-to-many relations.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findOne(?ConnectionInterface $con = null)
    {
        $criteria = $this->isKeepQuery() ? (clone $this)->keepQuery(false) : $this;
        $dataFetcher = $criteria->limit(1)->fetch($con);

        return $criteria->getFormatter()->init($criteria)->formatOne($dataFetcher);
    }

    /**
     * Issue a SELECT query based on the current ModelCriteria
     * and return the data fetcher.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con An optional connection object
     *
     * @return \Propel\Runtime\DataFetcher\DataFetcherInterface
     */
    public function fetch(?ConnectionInterface $con = null): DataFetcherInterface
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }

        $this->basePreSelect($con);

        return $this->doSelect($con);
    }

    /**
     * Find object by primary key
     * Behaves differently if the model has simple or composite primary key
     * <code>
     * // simple primary key
     * $book = $c->requirePk(12, $con);
     * // composite primary key
     * $bookOpinion = $c->requirePk(array(34, 634), $con);
     * </code>
     *
     * Throws an exception when nothing was found.
     *
     * @param mixed $key Primary key to use for the query
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\EntityNotFoundException|\Exception When nothing is found
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function requirePk($key, ?ConnectionInterface $con = null)
    {
        $result = $this->findPk($key, $con);

        if ($result === null) {
            throw $this->createEntityNotFoundException();
        }

        return $result;
    }

    /**
     * Issue a SELECT ... LIMIT 1 query based on the current ModelCriteria
     * and format the result with the current formatter
     * By default, returns a model object.
     *
     * Throws an exception when nothing was found.
     *
     * Does not work with ->with()s containing one-to-many relations.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\EntityNotFoundException|\Exception When nothing is found
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function requireOne(?ConnectionInterface $con = null)
    {
        $result = $this->findOne($con);

        if ($result === null) {
            throw $this->createEntityNotFoundException();
        }

        return $result;
    }

    /**
     * Apply a condition on a column and issues the SELECT ... LIMIT 1 query
     *
     * Throws an exception when nothing was found.
     *
     * @see filterBy()
     * @see findOne()
     *
     * @param mixed $column A string representing the column phpName, e.g. 'AuthorId'
     * @param mixed $value A value for the condition
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\EntityNotFoundException|\Exception When nothing is found
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function requireOneBy($column, $value, ?ConnectionInterface $con = null)
    {
        $result = $this->findOneBy($column, $value, $con);

        if ($result === null) {
            throw $this->createEntityNotFoundException();
        }

        return $result;
    }

    /**
     * Apply a list of conditions on columns and issues the SELECT ... LIMIT 1 query
     * <code>
     * $c->requireOneByArray([
     *  'Title' => 'War And Peace',
     *  'Publisher' => $publisher
     * ], $con);
     * </code>
     *
     * @see requireOne()
     *
     * @param mixed $conditions An array of conditions, using column phpNames as key
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Exception
     *
     * @return mixed the list of results, formatted by the current formatter
     */
    public function requireOneByArray($conditions, ?ConnectionInterface $con = null)
    {
        $result = $this->findOneByArray($conditions, $con);

        if ($result === null) {
            throw $this->createEntityNotFoundException();
        }

        return $result;
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return \Exception
     */
    private function createEntityNotFoundException(): Exception
    {
        if ($this->entityNotFoundExceptionClass === null) {
            throw new PropelException('Please define a entityNotFoundExceptionClass property with the name of your NotFoundException-class in ' . static::class);
        }

        /** @phpstan-var \Exception $exception */
        $exception = new $this->entityNotFoundExceptionClass("{$this->getModelShortName()} could not be found");

        return $exception;
    }

    /**
     * Issue a SELECT ... LIMIT 1 query based on the current ModelCriteria
     * and format the result with the current formatter
     * By default, returns a model object
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findOneOrCreate(?ConnectionInterface $con = null)
    {
        if ($this->joins) {
            throw new PropelException(__METHOD__ . ' cannot be used on a query with a join, because Propel cannot transform a SQL JOIN into a subquery. You should split the query in two queries to avoid joins.');
        }

        $ret = $this->findOne($con);
        if (!$ret) {
            /** @var class-string $class */
            $class = $this->getModelName();
            /** @phpstan-var \Propel\Runtime\ActiveRecord\ActiveRecordInterface $obj */
            $obj = new $class();
            if (method_exists($obj, 'setByName')) {
                $quoteColumnName = false;
                // turn column filters to values (this is very messy...)
                foreach ($this->filterCollector->getColumnFilters() as $filter) {
                    $columnIdentifier = $filter->getLocalColumnName($quoteColumnName);
                    $value = $filter->getValue();
                    $obj->setByName($columnIdentifier, $value, TableMap::TYPE_COLNAME);
                }
            }
            $ret = $this->getFormatter()->formatRecord($obj);
        }

        return $ret;
    }

    /**
     * Find object by primary key
     * Behaves differently if the model has simple or composite primary key
     * <code>
     * // simple primary key
     * $book = $c->findPk(12, $con);
     * // composite primary key
     * $bookOpinion = $c->findPk(array(34, 634), $con);
     * </code>
     *
     * @param mixed $key Primary key to use for the query
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findPk($key, ?ConnectionInterface $con = null)
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }

        // As the query uses a PK condition, no limit(1) is necessary.
        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $pkCols = array_values($this->getTableMapOrFail()->getPrimaryKeys());
        if (count($pkCols) === 1) {
            // simple primary key
            $pkCol = $pkCols[0];
            $column = new LocalColumnExpression($this, $this->getTableNameInQuery(), $pkCol);
            $criteria->addFilter($column, $key);
        } else {
            // composite primary key
            foreach ($pkCols as $pkCol) {
                $keyPart = array_shift($key);
                $column = new LocalColumnExpression($this, $this->getTableNameInQuery(), $pkCol);
                $criteria->addFilter($column, $keyPart);
            }
        }
        $dataFetcher = $criteria->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->formatOne($dataFetcher);
    }

    /**
     * Find objects by primary key
     * Behaves differently if the model has simple or composite primary key
     * <code>
     * // simple primary key
     * $books = $c->findPks(array(12, 56, 832), $con);
     * // composite primary key
     * $bookOpinion = $c->findPks(array(array(34, 634), array(45, 518), array(34, 765)), $con);
     * </code>
     *
     * @param array $keys Primary keys to use for the query
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return \Propel\Runtime\Collection\Collection<\Propel\Runtime\ActiveRecord\ActiveRecordInterface>|mixed the list of results, formatted by the current formatter
     */
    public function findPks(array $keys, ?ConnectionInterface $con = null)
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }
        // As the query uses a PK condition, no limit(1) is necessary.
        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $pkCols = $this->getTableMapOrFail()->getPrimaryKeys();
        if (count($pkCols) === 1) {
            // simple primary key
            $pkColumnMap = array_shift($pkCols);
            $column = new LocalColumnExpression($this, $this->getTableNameInQuery(), $pkColumnMap);
            $criteria->addFilter($column, $keys, Criteria::IN);
        } else {
            // composite primary key
            throw new PropelException('Multiple object retrieval is not implemented for composite primary keys');
        }
        $dataFetcher = $criteria->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->format($dataFetcher);
    }

    /**
     * Apply a condition on a column and issues the SELECT query
     *
     * @see filterBy()
     * @see find()
     *
     * @param string $column A string representing the column phpName, e.g. 'AuthorId'
     * @param mixed $value A value for the condition
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con An optional connection object
     *
     * @return \Propel\Runtime\Collection\Collection<\Propel\Runtime\ActiveRecord\ActiveRecordInterface>|mixed the list of results, formatted by the current formatter
     */
    public function findBy(string $column, $value, ?ConnectionInterface $con = null)
    {
        $method = 'filterBy' . $column;
        $this->$method($value);

        return $this->find($con);
    }

    /**
     * Apply a list of conditions on columns and issues the SELECT query
     * <code>
     * $c->findByArray(array(
     *  'Title' => 'War And Peace',
     *  'Publisher' => $publisher
     * ), $con);
     * </code>
     *
     * @see filterByArray()
     * @see find()
     *
     * @param mixed $conditions An array of conditions, using column phpNames as key
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return \Propel\Runtime\Collection\Collection<\Propel\Runtime\ActiveRecord\ActiveRecordInterface>|mixed the list of results, formatted by the current formatter
     */
    public function findByArray($conditions, ?ConnectionInterface $con = null)
    {
        $this->filterByArray($conditions);

        return $this->find($con);
    }

    /**
     * Apply a condition on a column and issues the SELECT ... LIMIT 1 query
     *
     * @see filterBy()
     * @see findOne()
     *
     * @param mixed $column A string representing thecolumn phpName, e.g. 'AuthorId'
     * @param mixed $value A value for the condition
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findOneBy($column, $value, ?ConnectionInterface $con = null)
    {
        $method = 'filterBy' . $column;
        $this->$method($value);

        return $this->findOne($con);
    }

    /**
     * Apply a list of conditions on columns and issues the SELECT ... LIMIT 1 query
     * <code>
     * $c->findOneByArray(array(
     *  'Title' => 'War And Peace',
     *  'Publisher' => $publisher
     * ), $con);
     * </code>
     *
     * @see filterByArray()
     * @see findOne()
     *
     * @param mixed $conditions An array of conditions, using column phpNames as key
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return mixed the list of results, formatted by the current formatter
     */
    public function findOneByArray($conditions, ?ConnectionInterface $con = null)
    {
        $this->filterByArray($conditions);

        return $this->findOne($con);
    }

    /**
     * Issue a SELECT COUNT(*) query based on the current ModelCriteria
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return int The number of results
     */
    public function count(?ConnectionInterface $con = null): int
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }

        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $criteria->setDbName($this->getDbName()); // Set the correct dbName
        $criteria->clearOrderByColumns(); // ORDER BY won't ever affect the count

        $dataFetcher = $criteria->doCount($con);
        /** @var array $row */
        $row = $dataFetcher->fetch();
        if ($row) {
            $count = (int)current($row);
        } else {
            $count = 0; // no rows returned; we infer that means 0 matches.
        }
        $dataFetcher->close();

        return $count;
    }

    /**
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con
     *
     * @return \Propel\Runtime\DataFetcher\DataFetcherInterface
     */
    public function doCount(?ConnectionInterface $con = null): DataFetcherInterface
    {
        $this->configureSelectColumns();

        // check that the columns of the main class are already added (if this is the primary ModelCriteria)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectColumns();
        }

        return parent::doCount($con);
    }

    /**
     * Issue an existence check on the current ModelCriteria
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return bool column existence
     */
    public function exists(?ConnectionInterface $con = null): bool
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }

        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $criteria->setDbName($this->getDbName()); // Set the correct dbName
        $criteria->clearOrderByColumns(); // ORDER BY will do nothing but slow down the query
        $criteria->clearSelectColumns(); // We are not retrieving data
        $criteria->addSelectColumn('1');
        $criteria->limit(1);

        $dataFetcher = $criteria->doSelect($con);
        $exists = (bool)$dataFetcher->fetchColumn(0);
        $dataFetcher->close();

        return $exists;
    }

    /**
     * Issue a SELECT query based on the current ModelCriteria
     * and uses a page and a maximum number of results per page
     * to compute an offset and a limit.
     *
     * @param int $page number of the page to start the pager on. Page 1 means no offset
     * @param int $maxPerPage maximum number of results per page. Determines the limit
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @return \Propel\Runtime\Util\PropelModelPager<\Propel\Runtime\ActiveRecord\ActiveRecordInterface> a pager object, supporting iteration
     */
    public function paginate(int $page = 1, int $maxPerPage = 10, ?ConnectionInterface $con = null): PropelModelPager
    {
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $pager = new PropelModelPager($criteria, $maxPerPage);
        $pager->setPage($page);
        $pager->init($con);

        return $pager;
    }

    /**
     * Code to execute before every DELETE statement
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface $con The connection object used by the query
     *
     * @return int|null
     */
    protected function basePreDelete(ConnectionInterface $con): ?int
    {
        return $this->preDelete($con);
    }

    /**
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     *
     * @return int|null
     */
    protected function preDelete(ConnectionInterface $con): ?int
    {
        return null;
    }

    /**
     * Code to execute after every DELETE statement
     *
     * @param int $affectedRows the number of deleted rows
     * @param \Propel\Runtime\Connection\ConnectionInterface $con The connection object used by the query
     *
     * @return int|null
     */
    protected function basePostDelete(int $affectedRows, ConnectionInterface $con): ?int
    {
        return $this->postDelete($affectedRows, $con);
    }

    /**
     * @param int $affectedRows
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     *
     * @return int|null
     */
    protected function postDelete(int $affectedRows, ConnectionInterface $con): ?int
    {
        return null;
    }

    /**
     * Issue a DELETE query based on the current ModelCriteria
     * An optional hook on basePreDelete() can prevent the actual deletion
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return int The number of deleted rows
     */
    public function delete(?ConnectionInterface $con = null): int
    {
        if ($this->countColumnFilters() === 0) {
            throw new PropelException(__METHOD__ . ' expects a Criteria with at least one condition. Use deleteAll() to delete all the rows of a table');
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection($this->getDbName());
        }

        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $criteria->setDbName($this->getDbName());

        try {
            return $con->transaction(function () use ($con, $criteria) {
                $affectedRows = $criteria->basePreDelete($con);
                if (!$affectedRows) {
                    $affectedRows = $criteria->doDelete($con);
                }
                $criteria->basePostDelete($affectedRows, $con);

                return $affectedRows;
            });
        } catch (PropelException $e) {
            throw new PropelException(__METHOD__ . ' is unable to delete. ', 0, $e);
        }
    }

    /**
     * Issue a DELETE query based on the current ModelCriteria deleting all rows in the table
     * An optional hook on basePreDelete() can prevent the actual deletion
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return int The number of deleted rows
     */
    public function deleteAll(?ConnectionInterface $con = null): int
    {
        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection($this->getDbName());
        }
        try {
            return $con->transaction(function () use ($con) {
                $affectedRows = $this->basePreDelete($con);
                if (!$affectedRows) {
                    $affectedRows = $this->doDeleteAll($con);
                }
                $this->basePostDelete($affectedRows, $con);

                return $affectedRows;
            });
        } catch (PropelException $e) {
            throw new PropelException(__METHOD__ . ' is unable to delete all. ', 0, $e);
        }
    }

    /**
     * Code to execute before every UPDATE statement
     *
     * @param array $values The associative array of columns and values for the update
     * @param \Propel\Runtime\Connection\ConnectionInterface $con The connection object used by the query
     * @param bool $forceIndividualSaves If false (default), the resulting call is a Criteria::doUpdate(), otherwise it is a series of save() calls on all the found objects
     *
     * @return int|null
     */
    protected function basePreUpdate(array &$values, ConnectionInterface $con, bool $forceIndividualSaves = false): ?int
    {
        return $this->preUpdate($values, $con, $forceIndividualSaves);
    }

    /**
     * @param array $values
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     * @param bool $forceIndividualSaves
     *
     * @return int|null
     */
    protected function preUpdate(array &$values, ConnectionInterface $con, bool $forceIndividualSaves = false): ?int
    {
        return null;
    }

    /**
     * Code to execute after every UPDATE statement
     *
     * @param int $affectedRows the number of updated rows
     * @param \Propel\Runtime\Connection\ConnectionInterface $con The connection object used by the query
     *
     * @return int|null
     */
    protected function basePostUpdate(int $affectedRows, ConnectionInterface $con): ?int
    {
        return $this->postUpdate($affectedRows, $con);
    }

    /**
     * @param int $affectedRows
     * @param \Propel\Runtime\Connection\ConnectionInterface $con
     *
     * @return int|null
     */
    protected function postUpdate(int $affectedRows, ConnectionInterface $con): ?int
    {
        return null;
    }

    /**
     * Issue an UPDATE query based the current ModelCriteria and a list of changes.
     * An optional hook on basePreUpdate() can prevent the actual update.
     * Beware that behaviors based on hooks in the object's save() method
     * will only be triggered if you force individual saves, i.e. if you pass true as second argument.
     *
     * @param mixed $values Associative array of keys and values to replace
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
     * @param bool $forceIndividualSaves If false (default), the resulting call is a Criteria::doUpdate(), otherwise it is a series of save() calls on all the found objects
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Exception|\Propel\Runtime\Exception\PropelException
     *
     * @return int Number of updated rows
     */
    public function update($values, ?ConnectionInterface $con = null, bool $forceIndividualSaves = false): int
    {
        if (!is_array($values) && !($values instanceof Criteria)) {
            throw new PropelException(__METHOD__ . ' expects an array or Criteria as first argument');
        }

        if (count($this->getJoins())) {
            throw new PropelException(__METHOD__ . ' does not support multitable updates, please do not use join()');
        }

        if ($con === null) {
            $con = Propel::getServiceContainer()->getWriteConnection($this->getDbName());
        }

        $criteria = $this->isKeepQuery() ? clone $this : $this;

        return $con->transaction(function () use ($con, $values, $criteria, $forceIndividualSaves) {
            $affectedRows = $criteria->basePreUpdate($values, $con, $forceIndividualSaves);
            if (!$affectedRows) {
                $affectedRows = $criteria->doUpdate($values, $con, $forceIndividualSaves);
            }
            $criteria->basePostUpdate($affectedRows, $con);

            return $affectedRows;
        });
    }

    /**
     * Issue an UPDATE query based the current ModelCriteria and a list of changes.
     * This method is called by ModelCriteria::update() inside a transaction.
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria|array|null $updateValues Associative array of keys and values to replace
     * @param \Propel\Runtime\Connection\ConnectionInterface $con a connection object
     * @param bool $forceIndividualSaves If false (default), the resulting call is a Criteria::doUpdate(), otherwise it is a series of save() calls on all the found objects
     *
     * @throws \Propel\Runtime\Exception\LogicException
     *
     * @return int Number of updated rows
     */
    public function doUpdate($updateValues, ConnectionInterface $con, bool $forceIndividualSaves = false): int
    {
        if ($forceIndividualSaves) {
            if ($updateValues instanceof Criteria) {
                throw new LogicException('Parameter #1 `$updateValues` must be an array while `$forceIndividualSaves = true`.');
            }
            // Update rows one by one
            $objects = $this->setFormatter(self::FORMAT_OBJECT)->find($con);
            foreach ($objects as $object) {
                foreach ($updateValues as $key => $value) {
                    $object->setByName($key, $value);
                }
            }
            $objects->save($con);
            $affectedRows = count($objects);
        } else {
            // update rows in a single query
            if ($updateValues instanceof Criteria) {
                $updateValues->turnFiltersToUpdateValues();
                $this->updateValues->merge($updateValues->updateValues);
            } elseif (is_array($updateValues)) {
                $tableMap = $this->getTableMapOrFail();
                foreach ($updateValues as $columnName => $value) {
                    $columnMap = $tableMap->getColumnByPhpName($columnName);
                    $this->setUpdateValue($columnMap, $value);
                }
            }

            $affectedRows = parent::doUpdate(null, $con);
            if (!$this->updateAffectsSingleRow()) {
                // clear instance pool if update affects multiple rows (we don't know which)
                $modelTableMapName = $this->modelTableMapName;
                if ($modelTableMapName === null) {
                    throw new LogicException('modelTableMapName is not set');
                }
                $modelTableMapName::clearInstancePool();
                $modelTableMapName::clearRelatedInstancePool();
            }
        }

        return $affectedRows;
    }

    /**
     * Tests if the filters in this query cover the full primary key.
     *
     * Currently does not consider operator, OR, etc. see commented test cases
     * in {@see \Propel\Tests\Runtime\ActiveQuery\ModelCriteriaPkFilterDetectionTest::UpdateAffectsSingleRowDataProvider()}.
     *
     * @return bool
     */
    protected function updateAffectsSingleRow(): bool
    {
        $pkCols = $this->getTableMapOrFail()->getPrimaryKeys();
        if (count($pkCols) !== $this->countColumnFilters()) {
            return false;
        }

        foreach ($pkCols as $pkCol) {
            $fqName = $pkCol->getFullyQualifiedName();
            $filter = $this->findFilterByColumn($fqName);
            if (!$filter) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $columnIdentifier
     * @param bool $hasAccessToOutputColumns If AS columns can be used in the statement (for example in HAVING clauses)
     * @param bool $failSilently
     *
     * @return \Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression
     */
    public function resolveColumn(string $columnIdentifier, bool $hasAccessToOutputColumns = false, bool $failSilently = true): AbstractColumnExpression
    {
        return $this->columnResolver->resolveColumn($columnIdentifier, $hasAccessToOutputColumns, $failSilently);
    }

    /**
     * Builds, binds and executes a SELECT query based on the current object.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con A connection object
     *
     * @return \Propel\Runtime\DataFetcher\DataFetcherInterface A dataFetcher using the connection, ready to be fetched
     */
    public function doSelect(?ConnectionInterface $con = null): DataFetcherInterface
    {
        $this->configureSelectColumns();
        $this->addSelfSelectColumns();

        return parent::doSelect($con);
    }

    /**
     * {@inheritDoc}
     *
     * @see \Propel\Runtime\ActiveQuery\Criteria::createSelectSql()
     *
     * @param array $params Parameters that are to be replaced in prepared statement.
     *
     * @return string
     */
    public function createSelectSql(array &$params): string
    {
        $this->configureSelectColumns();

        return parent::createSelectSql($params);
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    public function configureSelectColumns(): void
    {
        if (!$this->select) {
            return;
        }

        if ($this->formatter === null) {
            $this->setFormatter(SimpleArrayFormatter::class);
        }
        $this->selectColumns = [];

        foreach ($this->select as $columnName) {
            if (array_key_exists($columnName, $this->asColumns)) {
                continue;
            }
            $resolvedColumn = $this->columnResolver->resolveColumn($columnName);
            if ($resolvedColumn instanceof UnresolvedColumnExpression) {
                throw new PropelException("Cannot find selected column '$columnName'");
            }
            $localColumnName = $resolvedColumn->getColumnExpressionInQuery(true);
            // always put quotes around the columnName to be safe, we strip them in the formatter
            $this->addAsColumn('"' . $columnName . '"', $localColumnName);
        }
    }

    /**
     * @param string $columnName
     * @param bool $isPhpName
     *
     * @throws \Propel\Runtime\ActiveQuery\Exception\UnknownColumnException
     *
     * @return \Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\LocalColumnExpression
     */
    protected function resolveLocalColumnByName(string $columnName, bool $isPhpName = false): LocalColumnExpression
    {
        $tableMap = $this->getTableMapOrFail();
        try {
            $columnMap = $isPhpName ? $tableMap->getColumnByPhpName($columnName) : $tableMap->getColumn($columnName);
        } catch (ColumnNotFoundException $e) {
            throw new UnknownColumnException('Unknown column ' . $columnName . ' in model ', 0, $e); // required in tests
        }
        $tableAlias = $this->getTableNameInQuery();

        return new LocalColumnExpression($this, $tableAlias, $columnMap);
    }

    /**
     * Changes the table part of a a fully qualified column name if a true model alias exists
     * e.g. => 'book.TITLE' => 'b.TITLE'
     * This is for use as first argument of Criteria::add()
     *
     * @param string $colName the fully qualified column name, e.g 'book.TITLE' or BookTableMap::TITLE
     *
     * @return string the fully qualified column name, using table alias if applicable
     */
    public function getAliasedColName(string $colName): string
    {
        if ($this->useAliasInSQL) {
            return $this->modelAlias . substr($colName, (int)strrpos($colName, '.'));
        }

        return $colName;
    }

    /**
     * Handle the magic
     * Supports findByXXX(), findOneByXXX(), requireOneByXXX(), filterByXXX(), orderByXXX(), and groupByXXX() methods,
     * where XXX is a column phpName.
     * Supports XXXJoin(), where XXX is a join direction (in 'left', 'right', 'inner')
     *
     * @param string $name
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        // Maybe it's a magic call to one of the methods supporting it, e.g. 'findByTitle'
        static $methods = ['findBy', 'findOneBy', 'requireOneBy', 'filterBy', 'orderBy', 'groupBy'];
        foreach ($methods as $methodName) {
            $startsWithMethodName = strpos($name, $methodName) === 0;
            if (!$startsWithMethodName) {
                continue;
            }
            $columnExpression = substr($name, strlen($methodName));
            $isMultipleColumnsExpression = in_array($methodName, ['findBy', 'findOneBy', 'requireOneBy'], true) && strpos($columnExpression, 'And') !== false;
            if (!$isMultipleColumnsExpression) {
                return $this->$methodName($columnExpression, ...$arguments);
            }

            $arrayMethodName = $methodName . 'Array';
            $columnNames = explode('And', $columnExpression);
            $columnConditions = [];
            foreach ($columnNames as $columnName) {
                $columnConditions[$columnName] = array_shift($arguments);
            }

            return $this->$arrayMethodName($columnConditions, ...$arguments);
        }

        // Maybe it's a magic call to a qualified joinWith method, e.g. 'leftJoinWith' or 'joinWithAuthor'
        $pos = stripos($name, 'joinWith');
        if ($pos !== false) {
            $joinType = null;

            $type = substr($name, 0, $pos);
            if (in_array($type, ['left', 'right', 'inner'], true)) {
                $joinType = strtoupper($type) . ' JOIN';
            }

            $relation = substr($name, $pos + 8);
            if (!$relation) {
                $relation = $arguments[0];
                $joinType = $arguments[1] ?? $joinType;
            } else {
                $joinType = $arguments[0] ?? $joinType;
            }

            return $this->joinWith($relation, $joinType);
        }

        // Maybe it's a magic call to a qualified join method, e.g. 'leftJoin'
        $pos = strpos($name, 'Join');
        if ($pos > 0) {
            $type = substr($name, 0, $pos);
            if (in_array($type, ['left', 'right', 'inner'], true)) {
                $joinType = strtoupper($type) . ' JOIN';
                // Test if first argument is supplied, else don't provide an alias to joinXXX (default value)
                if (!isset($arguments[0])) {
                    $arguments[0] = null;
                }
                $arguments[] = $joinType;
                $methodName = lcfirst(substr($name, $pos));

                return $this->$methodName(...$arguments);
            }
        }

        return parent::__call($name, $arguments);
    }

    /**
     * Ensures deep cloning of attached objects
     *
     * @return void
     */
    public function __clone()
    {
        parent::__clone();

        foreach ($this->with as $key => $join) {
            $this->with[$key] = clone $join;
        }

        if ($this->formatter !== null) {
            $this->formatter = clone $this->formatter;
        }
    }

    /**
     * Override method to prevent an addition of self columns.
     *
     * @param \Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression|string $name
     *
     * @return $this
     */
    public function addSelectColumn($name)
    {
        $this->isSelfSelected = true;

        return parent::addSelectColumn($name);
    }

    /**
     * @return bool
     */
    protected function isEmpty(): bool
    {
        return parent::isEmpty() && !($this->formatter || $this->modelAlias || $this->with || $this->select);
    }
}
