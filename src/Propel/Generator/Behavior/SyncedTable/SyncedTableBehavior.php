<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\SyncedTable;

use Propel\Generator\Exception\SchemaException;
use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Index;
use Propel\Generator\Model\Table;
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
     * @var string
     */
    public const PARAMETER_KEY_SYNC = 'sync';

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
    public const PARAMETER_KEY_FOREIGN_KEYS = 'foreign_keys';

    /**
     * @var string
     */
    public const PARAMETER_KEY_SYNC_INDEXES = 'sync_indexes';

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
            static::PARAMETER_KEY_SYNC => 'true',
            static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS => 'false',
            static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS => 'false',
            static::PARAMETER_KEY_FOREIGN_KEYS => null,
            static::PARAMETER_KEY_SYNC_INDEXES => 'true',
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

        if ($tableExistsInSchema && !$this->parameterHasValue(static::PARAMETER_KEY_SYNC, 'true')) {
            return;
        }

        $this->syncTables();
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
     * @return \Propel\Generator\Model\Table
     */
    protected function syncTables(): Table
    {
        $syncedTable = $this->getSyncedTable();
        $sourceTable = $this->getTable();

        $columns = $sourceTable->getColumns();
        $this->syncColumns($syncedTable, $columns);

        $this->addCustomColumnsToSyncedTable($syncedTable);

        $foreignKeys = $this->getParameter(static::PARAMETER_KEY_FOREIGN_KEYS);
        if ($foreignKeys) {
            foreach ($foreignKeys as $fkData) {
                $this->createForeignKeyFromParameters($syncedTable, $fkData);
            }
        }

        $inheritFkRelations = $this->parameterHasValue(static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS, 'true');
        $inheritFkConstraints = $this->parameterHasValue(static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS, 'true');
        if ($inheritFkRelations || $inheritFkConstraints) {
            $foreignKeys = $sourceTable->getForeignKeys();
            $this->syncForeignKeys($syncedTable, $foreignKeys, $inheritFkConstraints);
        }

        if ($this->parameterHasValue(static::PARAMETER_KEY_SYNC_INDEXES, 'true')) {
            $indexes = $sourceTable->getIndices();
            $platform = $sourceTable->getDatabase()->getPlatform();
            $renameIndexes = $this->isDistinctiveIndexNameRequired($platform);
            $this->syncIndexes($syncedTable, $indexes, $renameIndexes);

            $uniqueIndexes = $sourceTable->getUnices();
            $this->syncUniqueIndexes($syncedTable, $uniqueIndexes);
        }

        $behaviors = $sourceTable->getDatabase()->getBehaviors();
        $this->reapplyBehaviors($behaviors);

        return $syncedTable;
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
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\Column> $columns
     *
     * @return void
     */
    protected function syncColumns(Table $syncedTable, array $columns)
    {
        foreach ($columns as $sourceColumn) {
            if ($syncedTable->hasColumn($sourceColumn)) {
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
     *
     * @return void
     */
    protected function syncForeignKeys(Table $syncedTable, array $foreignKeys, bool $inheritConstraints)
    {
        foreach ($foreignKeys as $foreignKey) {
            if ($syncedTable->containsForeignKeyWithSameName($foreignKey)) {
                continue;
            }
            $copiedForeignKey = clone $foreignKey;
            $copiedForeignKey->setSkipSql(!$inheritConstraints);
            $syncedTable->addForeignKey($copiedForeignKey);
        }
    }

    /**
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\Index> $indexes
     * @param bool $rename
     *
     * @return void
     */
    protected function syncIndexes(Table $syncedTable, array $indexes, bool $rename)
    {
        foreach ($indexes as $index) {
            $copiedIndex = clone $index;
            if ($rename) {
                // by removing the name, Propel will generate a unique name based on table and columns
                $copiedIndex->setName(null);
            }
            if ($syncedTable->hasIndex($index->getName())) {
                continue;
            }
            $syncedTable->addIndex($copiedIndex);
        }
    }

    /**
     * Create regular indexes from unique indexes on the given synced table.
     *
     * The synced table cannot use unique indexes, as even unique data on the
     * source table can be syncedd several times.
     *
     * @param \Propel\Generator\Model\Table $syncedTable
     * @param array<\Propel\Generator\Model\Unique> $uniqueIndexes
     *
     * @return void
     */
    protected function syncUniqueIndexes(Table $syncedTable, array $uniqueIndexes)
    {
        foreach ($uniqueIndexes as $unique) {
            $index = new Index();
            $index->setTable($syncedTable);
            foreach ($unique->getColumns() as $columnName) {
                $columnDef = [
                    'name' => $columnName,
                    'size' => $unique->getColumnSize($columnName),
                ];
                $index->addColumn($columnDef);
            }

            if ($syncedTable->hasIndex($index->getName())) {
                continue;
            }
            $syncedTable->addIndex($index);
        }
    }

    /**
     * @param array $behaviors
     *
     * @return void
     */
    protected function reapplyBehaviors(array $behaviors)
    {
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
