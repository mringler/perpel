
    /**
     * Export subset of object fields as specified by output group.
     *
     * @param string|array<string,string> $outputGroup  Name of the output group used for all tables or 
     *                                                  an array mapping model classes to output group name.
     *                                                  If a model class does not have a definition for the
     *                                                  given output group, the whole data is returned.
     * @param string $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME,
     *                                        TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME,
     *                                        TableMap::TYPE_NUM. Defaults to TableMap::TYPE_PHPNAME.
     * @param array $alreadyDumpedObjects   Internally used on recursion, typically not set by user (List of 
     *                                      objects to skip to avoid recursion).
     * @return array
     */
    public function toOutputGroup(
        $outputGroup,
        string $keyType = TableMap::TYPE_PHPNAME,
        array $alreadyDumpedObjects = []
    ): array
    {
        if (isset($alreadyDumpedObjects['<?= $objectClassName ?>'][$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        $alreadyDumpedObjects['<?= $objectClassName ?>'][$this->hashCode()] = true;
        $keys = <?= $tableMapClassName ?>::getFieldNames($keyType);
        $outputGroupName = is_string($outputGroup) ? $outputGroup : $outputGroup[get_class($this)] ?? $outputGroup['default'] ?? '';
        [
            'column_index' => $columnIndexes, 
            'relation' => $relationsLookup
        ] = <?= $tableMapClassName ?>::getOutputGroupData($outputGroupName);
        $result = [];

        foreach($columnIndexes as $columnIndex){
            $columnName = $keys[$columnIndex];
            $columnValue = $this->getByPosition($columnIndex);
            $result[$columnName] = $columnValue;
        }

<?php
    foreach($temporalColumnIndexesByFormatter as $formatter => $indexes):
        foreach($indexes as $index):
?>
        if (($result[$keys[<?= $index ?>]] ?? null) instanceof \DateTimeInterface) {
            $result[$keys[<?= $index ?>]] = $result[$keys[<?= $index ?>]]->format('<?= $formatter ?>');
        }
<?php
        endforeach;
    endforeach;
?>
        $virtualColumns = $this->virtualColumns;
        foreach ($virtualColumns as $key => $virtualColumn) {
            $result[$key] = $virtualColumn;
        }
<?php foreach($relationFormatterData as $relationFormatter): ?>

        if ($this-><?= $relationFormatter['localVariableName'] ?> !== null && ($relationsLookup === null || isset($relationsLookup['<?= $relationFormatter['relationName'] ?>']))) {
            <?= $relationFormatter['targetKeyLookupStatement'] ?>
<?php if ($relationFormatter['isCollection']): // call ObjectCollection::toOutputGroup() ?>
            $result[$key] = $this-><?= $relationFormatter['localVariableName'] ?>->toOutputGroup($outputGroup, null, $keyType, $alreadyDumpedObjects);
<?php else: // call Model::toOutputGroup() (this method) ?>
    
            if (!isset($alreadyDumpedObjects['<?= $relationFormatter['relationId'] ?>'][$this-><?= $relationFormatter['localVariableName']?>->hashCode()])){
                $alreadyDumpedObjects['<?= $relationFormatter['relationId'] ?>'][$this-><?= $relationFormatter['localVariableName']?>->hashCode()] = 1;
                $result[$key] = $this-><?= $relationFormatter['localVariableName'] ?>->toOutputGroup($outputGroup, $keyType, $alreadyDumpedObjects);
            }
<?php endif ?>
        }
<?php endforeach;?>

        return $result;
    }
