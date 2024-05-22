<?php
    /**
     * phpcs:ignoreFile
     *
     * Expected variables:
     * 
     * @var string $internalChangedValuesColumnPhpName
     * @var string $restoredChangedValuesColumnPhpName
     * @var string $auditEventColumnPhpName
     * @var string $auditedAtColumnName
     * @var string $auditObjectName
     * @var string $auditIdColumnName
     * @var array<\Propel\Generator\Model\Column> $syncedPkColumns
     * @var string $relationToSourceName
     * @var string $queryClassName
     */
?>
/**
 * @param <?= $auditObjectName ?> $audit1
 * @param <?= $auditObjectName ?> $audit2
 *
 * @return int
 */
protected function compareSourcePk(<?= $auditObjectName ?> $audit1, <?= $auditObjectName ?> $audit2): int
{
<?php 
    $comparisons = [];
    foreach($syncedPkColumns as $pkColumn){
        $comparatorPattern = $pkColumn->isNumericType() ? "%s - %s" : 'strcomp(%s, %s)';
        $columnAccessor = 'get' . $pkColumn->getPhpName().'()';
        $comparisons[] = sprintf($comparatorPattern, "\$audit2->$columnAccessor", "\$audit1->$columnAccessor");
    }
    $compareStatement = implode("\n    ?:", $comparisons);
?>
    return <?= $compareStatement ?>;
}

/**
 * @param array<<?= $auditObjectName ?>> $audits
 *
 * @return array<array<<?= $auditObjectName ?>>>
 */
protected static function groupAuditsBySource(array $audits): array
{
    $groups = [];
    foreach ($audits as $audit) {
<?php 
    $keyAccessors = array_map(fn($col) => '$audit->' . $col->getName(), $syncedPkColumns);
    $keyGetter = count($keyAccessors) === 1 ? $keyAccessors[0] : ('md5(json_encode(' . implode(', ', $keyAccessors) . '))');
?>
        $key = <?= $keyGetter ?>;
        $groups[$key] ?? ($groups[$key] = []);
        $groups[$key][] = $audit;
    }

    return $groups;
}

/**
 * @param ConnectionInterface $con (optional) The ConnectionInterface connection to use.
 *
 * @return array
 */
public function get<?= $restoredChangedValuesColumnPhpName ?>(ConnectionInterface $con = null): array
{
    if (!$this->hasVirtualColumn('<?= $restoredChangedValuesColumnPhpName ?>')) {
<?php
$chainedFilterStatements = implode('', array_map(fn($column) => "->filterBy{$column->getPhpName()}(\$this->{$column->getName()})", $syncedPkColumns));
?>
        $auditGroup = <?= $queryClassName ?>::create()<?= $chainedFilterStatements ?>->find($con)->toArrayCopy();
        $this->restoreAudits($auditGroup);
    }

    return $this->getVirtualColumn('<?= $restoredChangedValuesColumnPhpName ?>');
}

/**
 * Restores changed values of the given audit objects.
 *
 * Can process audits of different source objects.
 *
 * For correct results, the list has to include, for each given audit, all
 * existing later audits.
 *
 * @param array<<?= $auditObjectName ?>> $listOfAudits
 *
 * @return array<array<<?= $auditObjectName ?>>> The processed input object,
 *                                      grouped by source object id.  
 */
public static function restoreAudits(array $listOfAudits): array
{
    $groups = static::groupAuditsBySource($listOfAudits);
    $auditRows = [];
    foreach ($groups as $key => $audits) {
        if (!$audits) {
            continue;
        }
        usort($audits, fn(<?= $auditObjectName ?> $audit1, <?= $auditObjectName ?> $audit2) => (!$audit1-><?= $auditedAtColumnName ?> ? 1 : (!$audit2-><?= $auditedAtColumnName ?> ? -1 : (int) $audit2-><?= $auditedAtColumnName ?>->format('Uu') - (int) $audit1-><?= $auditedAtColumnName ?>->format('Uu'))));

        $sourceObject = $audits[0]->get<?= $relationToSourceName ?>();
        if (!$sourceObject && $audits[0]->get<?= $auditEventColumnPhpName ?>() !== 'delete') {
            throw new \RuntimeException('Cannot retrieve current values of audited object - looks like it was deleted without writing an audit log? AuditId: '.$audits[0]-><?= $auditIdColumnName ?>);
        } 
        $laterRow = $sourceObject ? $sourceObject->getAuditedColumnsCurrentValues() : $audits[0]->get<?= $internalChangedValuesColumnPhpName ?>();
        
        $groupRows = [];
        foreach ($audits as $audit) {
            $audit->setVirtualColumn('<?= $restoredChangedValuesColumnPhpName ?>', $audit->resolveEarlierSourceValues($laterRow));
            $groupRows[] = $audit;
            $laterRow = array_merge($laterRow, $audit->get<?= $internalChangedValuesColumnPhpName ?>());
        }
        if ($groupRows && $groupRows[count($groupRows) - 1]->get<?= $auditEventColumnPhpName ?>() !== 'insert') {
            $groupRows[] = static::createPreAuditEntry($laterRow);
        }
        $auditRows[$key] = $groupRows;
    }

    return $auditRows;
}

/**
 * Resolve values actually changed in audit.
 *
 * Override for custom output.
 *
 * @param array $laterValues
 *
 * @return array
 */
protected function resolveEarlierSourceValues(array $laterValues): array
{
    $overriddenValues = $this->get<?= $internalChangedValuesColumnPhpName ?>();

    return ($this->get<?= $auditEventColumnPhpName ?>() === 'insert') 
        ? array_merge($overriddenValues ?? [], $laterValues) 
        : array_intersect_key($laterValues, $overriddenValues );
}

/**
 *
 * @param array $values
 *
 * @return <?= $auditObjectName ?>

 */
protected static function createPreAuditEntry(array $values): <?= $auditObjectName ?>

{
    $audit = new <?= $auditObjectName ?>();
    $audit->set<?= $auditEventColumnPhpName ?>('pre-audit');
    $audit->setVirtualColumn('<?= $restoredChangedValuesColumnPhpName ?>', $values);

    return $audit;
}
