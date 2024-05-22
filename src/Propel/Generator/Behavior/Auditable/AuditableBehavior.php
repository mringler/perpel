<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\Auditable;

use Propel\Generator\Behavior\SyncedTable\TableSyncer\TableSyncer;
use Propel\Generator\Behavior\Util\InsertCodeBehavior;
use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Model\Table;
use stdClass;

class AuditableBehavior extends AuditableBehaviorDeclaration
{
    /**
     * @see \Propel\Generator\Model\Behavior::getObjectBuilderModifier()
     *
     * @return $this|\Propel\Generator\Behavior\Auditable\AuditableObjectModifier|\stdClass
     */
    public function getObjectBuilderModifier()
    {
        return ($this->omitOnSkipSql() && $this->table->isSkipSql()) ? new stdClass() : new AuditableObjectModifier($this);
    }

    /**
     * @return void
     */
    public function modifyTable(): void
    {
        parent::modifyTable();
        $this->addAggregationColumn($this->table);
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     *
     * @return void
     */
    public function addAggregationColumn(Table $table): void
    {
        $columnName = $this->aggregationColumnNameOnSource();
        if (!$columnName) {
            return;
        }
        TableSyncer::addColumnIfNotExists($table, $columnName, [
            'type' => 'INTEGER',
            'defaultValue' => 0,
            'defaultExpression' => 0,
            'required' => true,
        ]);
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param bool $tableExistsInSchema
     *
     * @return void
     */
    public function addTableElements(Table $syncedTable, bool $tableExistsInSchema): void
    {
        parent::addTableElements($syncedTable, $tableExistsInSchema);
        $auditedAtColumn = TableSyncer::addColumnIfNotExists($syncedTable, $this->getAuditedAtFieldName(), [
            'type' => 'TIMESTAMP',
            'defaultExpr' => 'CURRENT_TIMESTAMP',
        ]);
        $auditEventColumn = TableSyncer::addColumnIfNotExists($syncedTable, $this->getAuditEventFieldName(), [
            'type' => PropelTypes::ENUM,
            'valueSet' => 'insert, update, delete, pre-audit',
            'required' => true,
        ]);
        $internalChangedValuesColumn = TableSyncer::addColumnIfNotExists($syncedTable, $this->getInternalChangedValuesFieldName(), [
            'type' => $this->getChangedValuesFieldType(),
            'size' => $this->getChangedValuesFieldSize(),
        ]);

        $fk = $this->findSyncedRelation($syncedTable->getForeignKeys());
        $internalChangedValuesColumnPhpName = $internalChangedValuesColumn->getPhpName();

        InsertCodeBehavior::addToTable($this, $syncedTable, [
            'preInsert' => '$this->' . $auditedAtColumn->getName() . ' ??= new DateTime();',
            'objectMethods' => fn (ObjectBuilder $builder) => $this->renderLocalTemplate('auditObjectMethods', [
                'internalChangedValuesColumnPhpName' => $internalChangedValuesColumnPhpName,
                'restoredChangedValuesColumnPhpName' => $this->getChangedValuesFieldPhpName(),
                'auditObjectName' => $builder->getObjectClassName(),
                'auditIdColumnName' => $this->addPkAs(),
                'auditedAtColumnName' => $auditedAtColumn->getName(),
                'syncedPkColumns' => $this->getSyncedPrimaryKeyColumns($syncedTable),
                'relationToSourceName' => $builder->getFKPhpNameAffix($fk, false),
                'auditEventColumnPhpName' => $auditEventColumn->getPhpName(),
                'queryClassName' => $builder->getQueryClassName(),
            ]),
            'objectFilter' => fn (string $script) => $this->removeInternalFieldFromToArrayCode($script, $internalChangedValuesColumnPhpName),
        ]);
    }

    /**
     * @see \Propel\Generator\Builder\Om\ObjectBuilder::addToArray()
     *
     * @param string $script
     * @param string $columnPhpName
     *
     * @return string
     */
    protected function removeInternalFieldFromToArrayCode(string $script, string $columnPhpName): string
    {
        $pattern = '/^\s+\$keys\[\d+\] => \$this->get' . $columnPhpName . '\(\),\n/m';// remove line "$keys[5] => $this->getInternalChangedValues(),"

        return preg_replace($pattern, '', $script, 1);
    }

    /**
     * @return array<array{column: \Propel\Generator\Model\Column, isOmited: bool}>
     */
    public function selectAuditedFields(): array
    {
        $ignoredFields = $this->getIgnoredFieldNames();
        $omitedFields = $this->getOmitValueFields();
        $omitedTypes = $this->getOmitValueFieldTypes();
        $auditedFields = [];

        foreach ($this->table->getColumns() as $column) {
            $fieldName = $column->getName();
            if (in_array($fieldName, $ignoredFields)) {
                continue;
            }
            $isOmited = (in_array($fieldName, $omitedFields) || in_array($column->getType(), $omitedTypes));
            $auditedFields[] = [
                'column' => $column,
                'isOmited' => $isOmited,
            ];
        }

        return $auditedFields;
    }

    /**
     * @see Propel\Generator\Model\Behavior\Behavior::renderTemplate()
     *
     * @param string $filename
     * @param array $vars
     *
     * @return string
     */
    public function renderLocalTemplate(string $filename, array $vars = []): string
    {
        $templatePath = $this->getDirname() . '/templates/';

        return $this->renderTemplate($filename, $vars, $templatePath);
    }
}
