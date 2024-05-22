<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\Auditable;

use Propel\Generator\Builder\Om\ObjectBuilder;

class AuditableObjectModifier
{
    /**
     * @var string
     */
    private $dataRowAttributeName = '_dataRow';

    /**
     * @var \Propel\Generator\Behavior\Auditable\AuditableBehavior
     */
    protected $behavior;

    /**
     * @param \Propel\Generator\Behavior\Auditable\AuditableBehavior $behavior
     */
    public function __construct(AuditableBehavior $behavior)
    {
        $this->behavior = $behavior;
    }

    /**
     * @see \Propel\Generator\Model\Behavior::objectFilter()
     *
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function objectAttributes(ObjectBuilder $objectBuilder)
    {
        return "
/**
 * The data row this object was hydrated from.
 * 
 * @var array|null 
 */
protected \${$this->dataRowAttributeName} = null;
";

        // end auditable behavior
    }

    /**
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function objectMethods(ObjectBuilder $objectBuilder): string
    {
        $table = $this->behavior->getSyncedTable();
        $fk = $this->behavior->findSyncedRelation($objectBuilder->getTable()->getReferrers());
        $getPhpNameForFieldName = fn (string $fieldName) => $table->getColumn($fieldName)->getPhpName();

        return $this->behavior->renderLocalTemplate('sourceObjectMethods', [
            'objectBuilder' => $objectBuilder,
            'fk' => $fk,
            'dataRowAttributeName' => $this->dataRowAttributeName,
            'table' => $table,

            'auditedFields' => $this->behavior->selectAuditedFields(),
            'omitValue' => $this->behavior->getOmitValue(),

            'internalChangedValuesColumnPhpName' => $getPhpNameForFieldName($this->behavior->getInternalChangedValuesFieldName()),
            'auditEventColumnPhpName' => $getPhpNameForFieldName($this->behavior->getAuditEventFieldName()),
        ]);
    }

    /**
     * @see \Propel\Generator\Model\Behavior::objectFilter()
     *
     * @param string $script
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return void
     */
    public function objectFilter(string &$script, ObjectBuilder $objectBuilder)
    {
        $script = $this->updateHydrateCode($script, $objectBuilder);
    }

    /**
     * @see \Propel\Generator\Builder\Om\ObjectBuilder::addHydrate()
     *
     * @param string $script
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    protected function updateHydrateCode(string $script, ObjectBuilder $objectBuilder): string
    {
        $tableMapClassName = $objectBuilder->getTableMapClassName();
        $pattern = '/^\s*(public function hydrate\(.*\v\s*\{\n)/m'; // hydate() function header up until newline after '{'
        $code = <<< EOT
        // auditable behavior
        try {
            \$this->{$this->dataRowAttributeName} = (\$indexType === TableMap::TYPE_NUM)
                ? array_slice(\$row, \$startcol) 
                : array_map(fn(\$fn) => \$row[\$fn], {$tableMapClassName}::getFieldNames(\$indexType));
        } catch (Exception \$e) {
            throw new PropelException('Error extracting data row with numeric keys from input row to hydrate().', 0, \$e);
        }


EOT;

        return preg_replace_callback($pattern, fn (array $match) => "{$match[0]}{$code}", $script, 1);
    }

    /**
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function preUpdate(ObjectBuilder $objectBuilder)
    {
        $table = $this->behavior->getSyncedTable();
        $auditObjectClass = $table->getPhpName();
        $fk = $this->behavior->findSyncedRelation($objectBuilder->getTable()->getReferrers());
        $relationName = $objectBuilder->getRefFKPhpNameAffix($fk, false);
        $incrementStatement = $this->getIncrementAggregationColumnStatement("\n    ");

        return <<<EOT
\$audit = \$this->create{$auditObjectClass}('update');
if (\$ret && \$audit) {
    \$this->add{$relationName}(\$audit);{$incrementStatement}
}
EOT;
    }

    /**
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function preInsert(ObjectBuilder $objectBuilder)
    {
        $incrementStatement = $this->getIncrementAggregationColumnStatement();

        return $incrementStatement ? '$ret && ' . $incrementStatement : '';
    }

    /**
     * @param string $indent
     *
     * @return string
     */
    protected function getIncrementAggregationColumnStatement(string $indent = ''): string
    {
        $columnName = $this->behavior->aggregationColumnNameOnSource();
        if (!$columnName) {
            return '';
        }
        $phpColumnName = $this->behavior->getTable()->getColumn($columnName)->getPhpName();

        return <<<EOT
{$indent}\$this->set{$phpColumnName}((\$this->$columnName ?? 0) + 1);
EOT;
    }

    /**
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function postSave(ObjectBuilder $objectBuilder)
    {
        return $this->getUpdateDataRowValueStatement();
    }

    /**
     * @return string
     */
    protected function getUpdateDataRowValueStatement(): string
    {
        return <<<EOT
\$this->update{$this->dataRowAttributeName}();
EOT;
    }

    /**
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function postInsert(ObjectBuilder $objectBuilder)
    {
        $columns = $this->behavior->getAuditedColumnsOnInsert();
        $columnsExpression = ($columns)
            ? "[\n    '" . implode("',\n    '", $columns) . "'\n]"
            : 'null';

        return implode("\n", [
            $this->getUpdateDataRowValueStatement(), // necessary for other postInsert
            $this->buildCreateAuditStatements('insert', $objectBuilder, $columnsExpression, true, true, true),
        ]);
    }

    /**
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     *
     * @return string
     */
    public function postDelete(ObjectBuilder $objectBuilder)
    {
        if ($this->behavior->relationCascadesDelete()) {
            return '';
        }
        $columnsExpression = $objectBuilder->getTableMapClassName() . '::getFieldNames(TableMap::TYPE_COLNAME)';

        return implode("\n", [
            $this->buildCreateAuditStatements('delete', $objectBuilder, $columnsExpression, true, true, true),
            $this->getIncrementAggregationColumnStatement(),
        ]);
    }

    /**
     * @param string $auditEvent 'insert', 'update' or 'delete'
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     * @param string $columnsExpression
     * @param bool $saveManually
     * @param bool $useObjectValues
     * @param bool $forceCreate
     *
     * @return string
     */
    public function buildCreateAuditStatements(
        string $auditEvent,
        ObjectBuilder $objectBuilder,
        string $columnsExpression,
        bool $saveManually,
        bool $useObjectValues = false,
        bool $forceCreate = false
    ): string {
        $forceSaveExpression = var_export($saveManually, true);
        $useObjectValuesExpression = var_export($useObjectValues, true);
        $forceCreateExpression = var_export($forceCreate, true);

        return <<<EOT
\$this->addNewAudit('$auditEvent', $columnsExpression, $forceSaveExpression, $useObjectValuesExpression, $forceCreateExpression);
EOT;
    }
}
