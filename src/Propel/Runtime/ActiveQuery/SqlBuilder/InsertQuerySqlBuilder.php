<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Runtime\ActiveQuery\SqlBuilder;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;

class InsertQuerySqlBuilder extends AbstractSqlQueryBuilder
{
    /**
     * Build an Sql Insert statment for the given criteria.
     *
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria
     *
     * @return \Propel\Runtime\ActiveQuery\SqlBuilder\PreparedStatementDto
     */
    public static function createInsertSql(Criteria $criteria): PreparedStatementDto
    {
        $builder = new self($criteria);

        return $builder->build();
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return \Propel\Runtime\ActiveQuery\SqlBuilder\PreparedStatementDto
     */
    public function build(): PreparedStatementDto
    {
        $updateValues = $this->criteria->getUpdateValues();
        if (!$updateValues) {
            throw new PropelException('Database insert attempted without anything specified to insert.');
        }

        $tableName = reset($updateValues)->getTableAlias();
        $tableName = $this->quoteIdentifierTable($tableName);

        $columnCsv = $this->buildSimpleColumnNamesCsv($updateValues);

        $numberOfColumns = count($updateValues);
        $parameterPlaceholdersCsv = $this->buildParameterPlaceholdersCsv($numberOfColumns);

        $insertStatement = "INSERT INTO $tableName ($columnCsv) VALUES ($parameterPlaceholdersCsv)";
        $params = $this->buildParamsFromUpdateValues($updateValues);

        return new PreparedStatementDto($insertStatement, $params);
    }

    /**
     * @param array<\Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\UpdateColumn\AbstractUpdateColumn> $qualifiedColumnNames
     *
     * @return string
     */
    protected function buildSimpleColumnNamesCsv(array $qualifiedColumnNames): string
    {
        $columnNames = [];
        foreach ($qualifiedColumnNames as $updateColumn) {
            $columnNames[] = $updateColumn->getColumnExpressionInQuery(true); // TODO remove tableAlias
        }

        return implode(',', $columnNames);
    }

    /**
     * Build a comma separated list of placeholders, i.e. ":p1,:p2,:p3" for 3 placeholders.
     *
     * @param int $numberOfPlaceholders
     *
     * @return string
     */
    protected function buildParameterPlaceholdersCsv(int $numberOfPlaceholders): string
    {
        $parameterIndexes = ($numberOfPlaceholders < 1) ? [] : range(1, $numberOfPlaceholders);
        $parameterPlaceholders = preg_filter('/^/', ':p', $parameterIndexes); // prefix each element with ':p'

        return implode(',', $parameterPlaceholders);
    }
}
