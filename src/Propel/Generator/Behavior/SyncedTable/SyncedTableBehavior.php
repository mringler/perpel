<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\SyncedTable;

use Propel\Generator\Exception\SchemaException;
use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Index;
use Propel\Generator\Model\Table;
use Propel\Generator\Model\Unique;
use Propel\Generator\Platform\PgsqlPlatform;
use Propel\Generator\Platform\PlatformInterface;
use Propel\Generator\Platform\SqlitePlatform;

/**
 * Syncs another table definition to the one holding this behavior.
 *
 * Base for Archivable and Versionable behavior.
 */
class SyncedTableBehavior extends Behavior
{
    /**
     * If no table name is supplied for the synced table, the source table name
     * will be used ammended by this suffix.
     *
     * @var string DEFAULT_SYNCED_TABLE_SUFFIX
     */
    protected const DEFAULT_SYNCED_TABLE_SUFFIX = '_synced';

    /**
     * @var string
     */
    public const PARAMETER_KEY_SYNCED_TABLE = 'synced_table';

    /**
     * @var string
     */
    public const PARAMETER_KEY_SYNCED_PHPNAME = 'synced_phpname';

    /**
     * @var string The name of the added pk column ('id' if set to 'true').
     */
    public const PARAMETER_KEY_ADD_PK = 'add_pk';

    /**
     * @var string
     */
    public const PARAMETER_KEY_FOREIGN_KEYS = 'foreign_keys';

    /**
     * @var string
     */
    public const PARAMETER_KEY_SYNC = 'sync';

    /**
     * Parameter sets a foreign key with ON DELETE CASCADE on the synced primary key.
     *
     * @var string
     */
    public const PARAMETER_KEY_CASCADE_DELETES = 'cascade_deletes';

    /**
     * @var string
     */
    public const PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS = 'inherit_foreign_key_relations';

    /**
     * @var string
     */
    public const PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS = 'inherit_foreign_key_constraints';

    /**
     * @var string
     */
    public const PARAMETER_KEY_SYNC_INDEXES = 'sync_indexes';

    /**
     * Parameter can be set to 'index' or 'unique'.
     *
     * @var string
     */
    public const PARAMETER_KEY_SYNC_UNIQUE_AS = 'sync_unique_as';

    /**
     * List of column names (csv) that should not be synced.
     *
     * @var string
     */
    public const PARAMETER_KEY_IGNORE_COLUMNS = 'ignore_columns';

    /**
     * Either list of column names (csv) or 'true' to use ignored columns.
     *
     * @var string
     */
    public const PARAMETER_KEY_EMPTY_ACCESSOR_COLUMNS = 'empty_accessor_columns';

    /**
     * Ignore all columns expect PK.
     *
     * @var string
     */
    public const PARAMETER_KEY_SYNC_PK_ONLY = 'sync_pk_only';

    /**
     * @var \Propel\Generator\Model\Table|null
     */
    protected $syncedTable;

    /**
     * @return \Propel\Generator\Model\Table|null
     */
    public function getSyncedTable(): ?Table
    {
        return $this->syncedTable;
    }

    /**
     * @return void
     */
    protected function setupObject(): void
    {
        parent::setupObject();

        $this->setParameterDefaults();
    }

    /**
     * @return void
     */
    protected function setParameterDefaults(): void
    {
        $params = $this->getParameters();
        $defaultParams = $this->getDefaultParameters();
        $this->setParameters(array_merge($defaultParams, $params));
    }

    /**
     * @return array
     */
    protected function getDefaultParameters(): array
    {
        return [
            static::PARAMETER_KEY_SYNCED_TABLE => '',
            static::PARAMETER_KEY_SYNCED_PHPNAME => null,
            static::PARAMETER_KEY_ADD_PK => null,
            static::PARAMETER_KEY_SYNC => 'true',
            static::PARAMETER_KEY_FOREIGN_KEYS => null,
            static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS => 'false',
            static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS => 'false',
            static::PARAMETER_KEY_SYNC_INDEXES => 'false',
            static::PARAMETER_KEY_SYNC_UNIQUE_AS => null,
            static::PARAMETER_KEY_CASCADE_DELETES => 'false',
            static::PARAMETER_KEY_IGNORE_COLUMNS => null,
            static::PARAMETER_KEY_EMPTY_ACCESSOR_COLUMNS => null,
            static::PARAMETER_KEY_SYNC_PK_ONLY => 'false',
        ];
    }

    /**
     * @see \Propel\Generator\Model\Behavior::modifyDatabase()
     *
     * @return void
     */
    public function modifyDatabase(): void
    {
        foreach ($this->getDatabase()->getTables() as $table) {
            $this->addBehaviorToTable($table);
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     *
     * @return void
     */
    protected function addBehaviorToTable(Table $table): void
    {
        if ($table->hasBehavior($this->getId())) {
            // don't add the same behavior twice
            return;
        }
        $b = clone $this;
        $table->addBehavior($b);
    }

    /**
     * @see \Propel\Generator\Model\Behavior::modifyTable()
     *
     * @return void
     */
    public function modifyTable(): void
    {
        $this->addSyncedTable();
    }

    /**
     * @return string
     */
    protected function getSyncedTableName(): string
    {
        return $this->getParameter(static::PARAMETER_KEY_SYNCED_TABLE)
            ?: $this->getTable()->getOriginCommonName() . static::DEFAULT_SYNCED_TABLE_SUFFIX;
    }

    /**
     * @return void
     */
    protected function addSyncedTable(): void
    {
        $table = $this->getTable();
        $database = $table->getDatabase();
        $syncedTableName = $this->getSyncedTableName();

        $tableExistsInSchema = $database->hasTable($syncedTableName);

        $this->syncedTable = $tableExistsInSchema ?
            $database->getTable($syncedTableName) :
            $this->createSyncedTable();

        $this->addEmptyAccessorsToSyncedTable();

        if (!$tableExistsInSchema || $this->parameterHasValue(static::PARAMETER_KEY_SYNC, 'true')) {
            $this->syncTables();
        } else {
            $this->addCustomElements($this->syncedTable);
        }
    }

    /**
     * @return void
     */
    protected function addEmptyAccessorsToSyncedTable()
    {
        $emptyAccessorColumnNames = $this->parameters[static::PARAMETER_KEY_EMPTY_ACCESSOR_COLUMNS] ?? null;
        if ($emptyAccessorColumnNames === 'true') {
            $emptyAccessorColumnNames = implode(',', $this->getIgnoredColumnNames());
        }

        if (!$emptyAccessorColumnNames) {
            return;
        }
        EmptyColumnAccessorsBehavior::addToTable($this->syncedTable, $emptyAccessorColumnNames);
    }

    /**
     * @return \Propel\Generator\Model\Table
     */
    protected function createSyncedTable(): Table
    {
        $sourceTable = $this->getTable();
        $database = $sourceTable->getDatabase();

        return $database->addTable([
            'name' => $this->getSyncedTableName(),
            'phpName' => $this->getParameter(static::PARAMETER_KEY_SYNCED_PHPNAME),
            'package' => $sourceTable->getPackage(),
            'schema' => $sourceTable->getSchema(),
            'namespace' => $sourceTable->getNamespace() ? '\\' . $sourceTable->getNamespace() : null,
            'identifierQuoting' => $sourceTable->isIdentifierQuotingEnabled(),
        ]);
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     * @param string $parameterWithColumnName
     * @param array $columnDefinition
     *
     * @return void
     */
    protected function addColumnFromParameterIfNotExists(Table $table, string $parameterWithColumnName, array $columnDefinition): void
    {
        $columnName = $this->getParameter($parameterWithColumnName);
        $this->addColumnIfNotExists($table, $columnName, $columnDefinition);
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     * @param string $columnName
     * @param array $columnDefinition
     *
     * @return void
     */
    protected function addColumnIfNotExists(Table $table, string $columnName, array $columnDefinition): void
    {
        if ($table->hasColumn($columnName)) {
            return;
        }
        $columnDefinitionWithName = array_merge(['name' => $columnName], $columnDefinition);
        $table->addColumn($columnDefinitionWithName);
    }

    /**
     * @return array<string>
     */
    protected function getIgnoredColumnNames(): array
    {
        $ignoreColumnNames = $this->getDefaultValueForSet($this->parameters[static::PARAMETER_KEY_IGNORE_COLUMNS] ?? '') ?? [];
        $pkOnly = $this->parameterHasValue(static::PARAMETER_KEY_SYNC_PK_ONLY, 'true');
        if (!$pkOnly) {
            return $ignoreColumnNames;
        }
        $nonPkColumns = array_filter($this->table->getColumns(), fn (Column $column) => !$column->isPrimaryKey());
        $nonPkColumnNames = array_map(fn (Column $column) => $column->getName(), $nonPkColumns);

        return array_unique(array_merge($ignoreColumnNames, $nonPkColumnNames));
    }

    /**
     * @return \Propel\Generator\Model\Table
     */
    protected function syncTables(): Table
    {
        $syncedTable = $this->getSyncedTable();
        $sourceTable = $this->getTable();

        $columns = $sourceTable->getColumns();
        $ignoreColumnNames = $this->getIgnoredColumnNames();
        $this->syncColumns($syncedTable, $columns, $ignoreColumnNames);

        if ($this->parameterHasValue(static::PARAMETER_KEY_CASCADE_DELETES, 'true')) {
            $this->addCascadingForeignKeyToSyncedTable($syncedTable, $sourceTable);
        }

        $this->addCustomElements($syncedTable);

        $inheritFkRelations = $this->parameterHasValue(static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS, 'true');
        $inheritFkConstraints = $this->parameterHasValue(static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS, 'true');
        if ($inheritFkRelations || $inheritFkConstraints) {
            $foreignKeys = $sourceTable->getForeignKeys();
            $this->syncForeignKeys($syncedTable, $foreignKeys, $inheritFkConstraints, $ignoreColumnNames);
        }

        if ($this->parameterHasValue(static::PARAMETER_KEY_SYNC_INDEXES, 'true')) {
            $indexes = $sourceTable->getIndices();
            $platform = $sourceTable->getDatabase()->getPlatform();
            $renameIndexes = $this->isDistinctiveIndexNameRequired($platform);
            $this->syncIndexes($syncedTable, $indexes, $renameIndexes, $ignoreColumnNames);
        }

        if (in_array($this->parameters[static::PARAMETER_KEY_SYNC_UNIQUE_AS], ['unique', 'index'])) {
            $asIndex = $this->parameters[static::PARAMETER_KEY_SYNC_UNIQUE_AS] !== 'unique';
            $uniqueIndexes = $sourceTable->getUnices();
            $this->syncUniqueIndexes($asIndex, $syncedTable, $uniqueIndexes, $ignoreColumnNames);
        }

        $this->reapplyTableBehaviors($sourceTable);

        return $syncedTable;
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     *
     * @return void
     */
    protected function addCustomElements(Table $syncedTable)
    {
        $this->addCustomColumnsToSyncedTable($syncedTable);
        $this->addCustomForeignKeysToSyncedTable($syncedTable);
    }

    /**
     * Allows inheriting classes to add columns.
     *
     * @param \Propel\Generator\Model\Table $syncedTable
     *
     * @return void
     */
    protected function addCustomColumnsToSyncedTable(Table $syncedTable)
    {
        $this->addPkColumn($syncedTable);
    }

    /**
     * Allows inheriting classes to add columns.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return void
     */
    protected function addPkColumn(Table $table)
    {
        $pkParamValue = $this->parameters[static::PARAMETER_KEY_ADD_PK] ?? null;
        if (!$pkParamValue || $pkParamValue === 'false') {
            return;
        }
        $idColumnName = ($pkParamValue === 'true') ? 'id' : $pkParamValue;
        $this->addColumnIfNotExists($table, $idColumnName, [
            'type' => 'INTEGER',
            'required' => 'true',
            'primaryKey' => 'true',
            'autoIncrement' => 'true',
        ]);
        foreach ($table->getPrimaryKey() as $pkColumn) {
            if ($pkColumn->getName() === $idColumnName) {
                continue;
            }
            $pkColumn->setPrimaryKey(false);
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param \Propel\Generator\Model\Table $sourceTable
     *
     * @return void
     */
    protected function addCascadingForeignKeyToSyncedTable(Table $syncedTable, Table $sourceTable): void
    {
        $fk = new ForeignKey();
        $fk->setForeignTableCommonName($sourceTable->getCommonName());
        $fk->setForeignSchemaName($sourceTable->getSchema());
        $fk->setOnDelete('CASCADE');
        $fk->setOnUpdate(null);
        foreach ($sourceTable->getPrimaryKey() as $sourceColumn) {
            $syncedColumn = $syncedTable->getColumn($sourceColumn->getName());
            $fk->addReference($syncedColumn, $sourceColumn);
        }
        $syncedTable->addForeignKey($fk);
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     *
     * @return void
     */
    protected function addCustomForeignKeysToSyncedTable(Table $syncedTable)
    {
        $foreignKeys = $this->getParameter(static::PARAMETER_KEY_FOREIGN_KEYS);
        if ($foreignKeys) {
            foreach ($foreignKeys as $fkData) {
                $this->createForeignKeyFromParameters($syncedTable, $fkData);
            }
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\Column> $columns
     * @param array<string> $ignoreColumnNames
     *
     * @return void
     */
    protected function syncColumns(Table $syncedTable, array $columns, array $ignoreColumnNames)
    {
        foreach ($columns as $sourceColumn) {
            if (in_array($sourceColumn->getName(), $ignoreColumnNames) || $syncedTable->hasColumn($sourceColumn)) {
                continue;
            }
            $syncedColumn = clone $sourceColumn;
            $syncedColumn->clearReferrers();
            $syncedColumn->clearInheritanceList();
            $syncedColumn->setAutoIncrement(false);
            $syncedTable->addColumn($syncedColumn);
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\ForeignKey> $foreignKeys
     * @param bool $inheritConstraints
     * @param array<string> $ignoreColumnNames
     *
     * @return void
     */
    protected function syncForeignKeys(Table $syncedTable, array $foreignKeys, bool $inheritConstraints, array $ignoreColumnNames)
    {
        foreach ($foreignKeys as $originalForeignKey) {
            if (
                $syncedTable->containsForeignKeyWithSameName($originalForeignKey)
                || array_intersect($originalForeignKey->getLocalColumns(), $ignoreColumnNames)
            ) {
                continue;
            }
            $syncedForeignKey = clone $originalForeignKey;
            $syncedForeignKey->setSkipSql(!$inheritConstraints);
            $syncedTable->addForeignKey($syncedForeignKey);
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\Index> $indexes
     * @param bool $rename
     * @param array<string> $ignoreColumnNames
     *
     * @return void
     */
    protected function syncIndexes(Table $syncedTable, array $indexes, bool $rename, array $ignoreColumnNames)
    {
        foreach ($indexes as $originalIndex) {
            $index = clone $originalIndex;

            if (!$this->removeColumnsFromIndex($index, $ignoreColumnNames)) {
                continue;
            }

            if ($rename) {
                // by removing the name, Propel will generate a unique name based on table and columns
                $index->setName(null);
            }
            if ($syncedTable->hasIndex($index->getName())) {
                continue;
            }
            $syncedTable->addIndex($index);
        }
    }

    /**
     * @param \Propel\Generator\Model\Index $index
     * @param array $columnNames
     *
     * @return \Propel\Generator\Model\Index|null Returns null if the index has no remaining columns.
     */
    protected function removeColumnsFromIndex(Index $index, array $columnNames): ?Index
    {
        $ignoredColumnsInIndex = array_intersect($index->getColumns(), $columnNames);
        if (!$ignoredColumnsInIndex) {
            return $index;
        }
        if (count($ignoredColumnsInIndex) === count($index->getColumns())) {
            return null;
        }
        $indexColumns = array_filter($index->getColumnObjects(), fn (Column $col) => !in_array($col->getName(), $ignoredColumnsInIndex));
        $index->setColumns($indexColumns);

        return $index;
    }

    /**
     * Create regular indexes from unique indexes on the given synced table.
     *
     * The synced table cannot use unique indexes, as even unique data on the
     * source table can be syncedd several times.
     *
     * @param bool $asIndex
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\Unique> $uniqueIndexes
     * @param array<string> $ignoreColumnNames
     *
     * @return void
     */
    protected function syncUniqueIndexes(bool $asIndex, Table $syncedTable, array $uniqueIndexes, array $ignoreColumnNames)
    {
        $indexClass = $asIndex ? Index::class : Unique::class;
        foreach ($uniqueIndexes as $unique) {
            if (array_intersect($unique->getColumns(), $ignoreColumnNames)) {
                continue;
            }
            $index = new $indexClass();
            $index->setTable($syncedTable);
            foreach ($unique->getColumns() as $columnName) {
                $columnDef = [
                    'name' => $columnName,
                    'size' => $unique->getColumnSize($columnName),
                ];
                $index->addColumn($columnDef);
            }

            $existingIndexes = $asIndex ? $syncedTable->getIndices() : $syncedTable->getUnices();
            $existingIndexNames = array_map(fn ($index) => $index->getName(), $existingIndexes);
            if (in_array($index->getName(), $existingIndexNames)) {
                continue;
            }
            $asIndex ? $syncedTable->addIndex($index) : $syncedTable->addUnique($index);
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     *
     * @return void
     */
    protected function reapplyTableBehaviors(Table $table)
    {
        $behaviors = $table->getDatabase()->getBehaviors();
        foreach ($behaviors as $behavior) {
            if ($behavior instanceof SyncedTableBehavior) {
                continue;
            }
            $behavior->modifyDatabase();
        }
    }

    /**
     * @psalm-param array{name?: string, localColumn: string, foreignTable: string, foreignColumn: string, relationOnly?: string} $fkParameterData
     *
     * @param \Propel\Generator\Model\Table $table
     * @param array $fkParameterData
     *
     * @throws \Propel\Generator\Exception\SchemaException
     *
     * @return void
     */
    protected function createForeignKeyFromParameters(Table $table, array $fkParameterData): void
    {
        if (
            empty($fkParameterData['localColumn']) ||
            empty($fkParameterData['foreignColumn'])
        ) {
            $tableName = $this->table->getName();
            $behavior = self::class;

            throw new SchemaException("Table `$tableName`: $behavior misses foreign key parameters. Please supply `localColumn`, `foreignTable` and `foreignColumn` for every entry");
        }

        $fk = new ForeignKey($fkParameterData['name'] ?? null);
        $fk->addReference($fkParameterData['localColumn'], $fkParameterData['foreignColumn']);
        $table->addForeignKey($fk);
        $fk->loadMapping($fkParameterData);
    }

    /**
     * @param \Propel\Generator\Platform\PlatformInterface|null $platform
     *
     * @return bool
     */
    protected function isDistinctiveIndexNameRequired(?PlatformInterface $platform): bool
    {
        return $platform instanceof PgsqlPlatform || $platform instanceof SqlitePlatform;
    }
}
