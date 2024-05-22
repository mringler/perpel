<?php
    /**
     * phpcs:ignoreFile
     *
     * Expected variables:
     * 
     * @var array<array{column: \Propel\Generator\Model\Column, isOmited: bool}> $auditedFields
     * @var \Propel\Generator\Builder\Om\ObjectBuilder $objectBuilder
     * @var \Propel\Generator\Model\ForeignKey $fk
     * @var \Propel\Generator\Model\Table $table
     * @var string $dataRowAttributeName
     * @var string $auditEventColumnPhpName
     * @var string $internalChangedValuesColumnPhpName
     * @var string $omitValue
     * 
     */

    $auditObjectImportedClass = $objectBuilder->getClassNameFromTable($table);
?>

/**
 * Set row values to current data.
 */
protected function update<?= $dataRowAttributeName ?>(): void
{
    $this-><?= $dataRowAttributeName ?> = $this->toArray(TableMap::TYPE_NUM, false);
}

/**
 * @param string $auditEvent 'insert', 'update' or 'delete'
 * @param array|null $selectedColumns Optional parameter to override audited columns.
 * @param bool $forceSave Immediately save the audit after creation.
 * @param bool $useCurrent Use current data instead of row values.
 * @param bool $forceCreate Create audit even if there are no changes.
 *
 * @return void
 */
protected function addNewAudit(
    string $auditEvent,
    ?array $selectedColumns = null,
    bool $forceSave = false,
    bool $useCurrent = false,
    bool $forceCreate = false
): void
{
    $audit = $this->create<?= $table->getPhpName() ?>($auditEvent, $selectedColumns, $useCurrent, $forceCreate);
    if (!$audit) {
        return;
    }
    $this->add<?= $objectBuilder->getRefFKPhpNameAffix($fk, false) ?>($audit);
    if ($forceSave){
        $audit->save();
    }
}

/**
 * Create an audit for modified or specified columns. 
 *
 * @param string $auditEvent 'insert', 'update' or 'delete'
 * @param array|null $selectedColumns Optional parameter to override audited columns.
 * @param bool $useCurrent Use current data instead of row values.
 * @param bool $forceCreate Create audit even if there are no changes.
 *
 * @return <?= $table->getPhpName() ?>|null 
 */
protected function create<?= $table->getPhpName() ?>(
    string $auditEvent,
    ?array $selectedColumns = null,
    bool $useCurrent = false,
    bool $forceCreate = false
): ?<?= $table->getPhpName() ?>

{
    $auditData = $this->buildAuditChanges($selectedColumns, $useCurrent);
    if (!$auditData && !$forceCreate) {
        return null;
    }
    $audit = new <?= $objectBuilder->getClassNameFromTable($table) ?>();
    $audit->set<?= $auditEventColumnPhpName ?>($auditEvent);
    $audit->set<?= $internalChangedValuesColumnPhpName ?>($auditData);

    return $audit;
}

/**
 * @param string $auditEvent 'insert', 'update' or 'delete'
 * Build list of changed column names and their old value.
 *
 * @param array|null $selectedColumns Optional parameter to override audited columns.
 * @param bool $useCurrent Use current data instead of row values.
 *
 * @return array<string, mixed>
 */
protected function buildAuditChanges(?array $selectedColumns = null, $useCurrent = false): array
{
    if (!$useCurrent && $this-><?= $dataRowAttributeName ?> === null){
        throw new \RuntimeException('Trying to create audit without row values.');
    }

    $overwrittenValues = [];
    $columnKeys = $selectedColumns ?: array_keys($this->modifiedColumns);
    $values = $useCurrent ?  $this->toArray(TableMap::TYPE_NUM, false) : $this-><?= $dataRowAttributeName ?>;
    foreach ($columnKeys as $qualifiedColumnName) {
        switch ($qualifiedColumnName) {
<?php foreach ($auditedFields as $fieldData):
    /** @var \Propel\Generator\Model\Column */
    $column = $fieldData['column'];
    $fieldIndex = $column->getPosition()-1 ;
    $valueGetter = $fieldData['isOmited'] ? "'$omitValue'" : "\$values[$fieldIndex]";
?>

            case <?= $column->getFQConstantName() ?>:
                $overwrittenValues['<?= $column->getName() ?>'] = $this->getAuditFieldValue($qualifiedColumnName, <?= $valueGetter ?>);

                break;
<?php endforeach ?>
        }
    }

    return $overwrittenValues;
}

/**
 * @return array
 */
public function getAuditedColumnsCurrentValues(): array
{
    $auditedColumns = <?= $objectBuilder->getTableMapClassName() ?>::getFieldNames(TableMap::TYPE_COLNAME);

    return $this->buildAuditChanges($auditedColumns, true);
}

/**
 * Determines column values in audits. Can be overridden to set custom values.
 *
 * @param string $qualifiedColumnName The column name as stored in the TableMap const (i.e. BookTableMap::COL_ID).
 * @param mixed $defaultAuditValue The audit value according to configuration.
 *
 * @return mixed 
 */
protected function getAuditFieldValue(string $qualifiedColumnName, $defaultAuditValue)
{
    return $defaultAuditValue;
}

/**
 * Load the complet audit with restored change values.
 *
 * @param ConnectionInterface|null $con
 *
 * @return ObjectCollection The audit objects ordered by audit
 *                          date in descending order (latest change first).
 */
public function restoreAudit(?ConnectionInterface $con = null): ObjectCollection
{
    if (!$this->hasVirtualColumn('RestoredAudit')) {
        $auditObjects = $this->get<?= $objectBuilder->getRefFKPhpNameAffix($fk, true) ?>($con);
        $auditGroups = <?= $table->getPhpName() ?>::restoreAudits($auditObjects->getArrayCopy());
        $restoredAudit = $auditGroups ? reset($auditGroups) : $auditGroups;
        $auditObjects->exchangeArray($restoredAudit); // fixes order
        $this->setVirtualColumn('RestoredAudit', $auditObjects);
    }

    return $this->getVirtualColumn('RestoredAudit');
}

// end auditable behavior
