<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\Versionable;

use Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Table;

/**
 * Keeps tracks of all the modifications in an ActiveRecord object
 *
 * @author Francois Zaninotto
 */
class VersionableBehavior extends SyncedTableBehavior
{
 /**
  * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior::DEFAULT_SYNCED_TABLE_SUFFIX
  *
  * @var string DEFAULT_SYNCED_TABLE_SUFFIX
  */
    protected const DEFAULT_SYNCED_TABLE_SUFFIX = '_version';

    /**
     * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior::PARAMETER_KEY_SYNCED_TABLE
     *
     * @var string
     */
    public const PARAMETER_KEY_SYNCED_TABLE = 'version_table';

    /**
     * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior::PARAMETER_KEY_SYNCED_PHPNAME
     *
     * @var string
     */
    public const PARAMETER_KEY_SYNCED_PHPNAME = 'version_phpname';

    /**
     * @var string
     */
    public const PARAMETER_KEY_SYNC_INDEXES = 'indices';

    /**
     * @var \Propel\Generator\Model\Table
     */
    protected $versionTable;

    /**
     * @var \Propel\Generator\Behavior\Versionable\VersionableBehaviorObjectBuilderModifier|null
     */
    protected $objectBuilderModifier;

    /**
     * @var \Propel\Generator\Behavior\Versionable\VersionableBehaviorQueryBuilderModifier|null
     */
    protected $queryBuilderModifier;

    /**
     * @var int
     */
    protected $tableModificationOrder = 80;

    /**
     * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior::getDefaultParameters()
     *
     * @return array
     */
    protected function getDefaultParameters(): array
    {
        return [
            'version_column' => 'version',
            static::PARAMETER_KEY_SYNCED_TABLE => '',
            static::PARAMETER_KEY_SYNCED_PHPNAME => null,
            static::PARAMETER_KEY_SYNC => 'false',
            static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS => 'false',
            static::PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS => 'false',
            static::PARAMETER_KEY_FOREIGN_KEYS => null,
            static::PARAMETER_KEY_SYNC_INDEXES => 'false',
            static::PARAMETER_KEY_SYNC_UNIQUE_AS => null,
            'log_created_at' => 'false',
            'log_created_by' => 'false',
            'log_comment' => 'false',
            'version_created_at_column' => 'version_created_at',
            'version_created_by_column' => 'version_created_by',
            'version_comment_column' => 'version_comment',
        ];
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     *
     * @return void
     */
    protected function addBehaviorToTable(Table $table): void
    {
        if (property_exists($table, 'isVersionTable')) {
            // don't add the behavior to version tables
            return;
        }
        parent::addBehaviorToTable($table);
    }

    /**
     * @return void
     */
    public function modifyTable(): void
    {
        $this->addVersionColumnToSourceTable();
        $this->addLogColumnsToSourceTable();
        $this->addVersionTable();
        $this->addForeignKeyVersionColumns();
    }

    /**
     * @return void
     */
    protected function addVersionColumnToSourceTable(): void
    {
        $table = $this->getTable();
        // add the version column
        if (!$table->hasColumn($this->getParameter('version_column'))) {
            $table->addColumn([
                'name' => $this->getParameter('version_column'),
                'type' => 'INTEGER',
                'default' => 0,
            ]);
        }
    }

    /**
     * @return void
     */
    protected function addLogColumnsToSourceTable(): void
    {
        $table = $this->getTable();
        if ($this->getParameter('log_created_at') === 'true' && !$table->hasColumn($this->getParameter('version_created_at_column'))) {
            $table->addColumn([
                'name' => $this->getParameter('version_created_at_column'),
                'type' => 'TIMESTAMP',
            ]);
        }
        if ($this->getParameter('log_created_by') === 'true' && !$table->hasColumn($this->getParameter('version_created_by_column'))) {
            $table->addColumn([
                'name' => $this->getParameter('version_created_by_column'),
                'type' => 'VARCHAR',
                'size' => 100,
            ]);
        }
        if ($this->getParameter('log_comment') === 'true' && !$table->hasColumn($this->getParameter('version_comment_column'))) {
            $table->addColumn([
                'name' => $this->getParameter('version_comment_column'),
                'type' => 'VARCHAR',
                'size' => 255,
            ]);
        }
    }

    /**
     * @return void
     */
    protected function addVersionTable(): void
    {
        // required for BC
        $this->parameters[static::PARAMETER_KEY_SYNCED_PHPNAME] ??= $this->getTable()->getPhpName() . 'Version';

        $tablePreexistsInSchema = $this->getTable()->getDatabase()->hasTable($this->getSyncedTableName());
        $this->addSyncedTable();
        if ($tablePreexistsInSchema && !$this->parameterHasValue(static::PARAMETER_KEY_SYNC, 'true')) {
            return;
        }

        $this->syncedTable->isVersionTable = true;
        $this->syncedTable->setSkipSql($this->getTable()->isSkipSql());
        $this->setSyncedTableForeignKeys();

        // add the version column to the primary key
        $versionColumn = $this->syncedTable->getColumn($this->getParameter('version_column'));
        $versionColumn->setNotNull(true);
        $versionColumn->setPrimaryKey(true);
    }

    /**
     * @return void
     */
    public function setSyncedTableForeignKeys(): void
    {
        $table = $this->getTable();
        $fk = new ForeignKey();
        $fk->setForeignTableCommonName($table->getCommonName());
        $fk->setForeignSchemaName($table->getSchema());
        $fk->setOnDelete('CASCADE');
        $fk->setOnUpdate(null);
        $tablePKs = $table->getPrimaryKey();
        foreach ($this->syncedTable->getPrimaryKey() as $key => $column) {
            $fk->addReference($column, $tablePKs[$key]);
        }
        $this->syncedTable->addForeignKey($fk);
    }

    /**
     * @return void
     */
    public function addForeignKeyVersionColumns(): void
    {
        $versionTable = $this->syncedTable;
        foreach ($this->getVersionableFks() as $fk) {
            $fkVersionColumnName = $fk->getLocalColumnName() . '_version';
            if (!$versionTable->hasColumn($fkVersionColumnName)) {
                $versionTable->addColumn([
                    'name' => $fkVersionColumnName,
                    'type' => 'INTEGER',
                    'default' => 0,
                ]);
            }
        }

        foreach ($this->getVersionableReferrers() as $fk) {
            $fkTableName = $fk->getTable()->getName();
            $fkIdsColumnName = $fkTableName . '_ids';
            if (!$versionTable->hasColumn($fkIdsColumnName)) {
                $versionTable->addColumn([
                    'name' => $fkIdsColumnName,
                    'type' => 'ARRAY',
                ]);
            }

            $fkVersionsColumnName = $fkTableName . '_versions';
            if (!$versionTable->hasColumn($fkVersionsColumnName)) {
                $versionTable->addColumn([
                    'name' => $fkVersionsColumnName,
                    'type' => 'ARRAY',
                ]);
            }
        }
    }

    /**
     * @return \Propel\Generator\Model\Table
     */
    public function getVersionTable(): Table
    {
        return $this->syncedTable;
    }

    /**
     * @return list<\Propel\Generator\Model\ForeignKey>
     */
    public function getVersionableFks(): array
    {
        $versionableForeignKeys = [];
        if (!$this->getTable()) {
            return $versionableForeignKeys;
        }

        foreach ($this->getTable()->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getForeignTable()->hasBehavior($this->getName()) && !$foreignKey->isComposite()) {
                $versionableForeignKeys[] = $foreignKey;
            }
        }

        return $versionableForeignKeys;
    }

    /**
     * @return list<\Propel\Generator\Model\ForeignKey>
     */
    public function getVersionableReferrers(): array
    {
        $versionableReferrers = [];
        if (!$this->getTable()) {
            return $versionableReferrers;
        }

        foreach ($this->getTable()->getReferrers() as $foreignKey) {
            if ($foreignKey->getTable()->hasBehavior($this->getName()) && !$foreignKey->isComposite()) {
                $versionableReferrers[] = $foreignKey;
            }
        }

        return $versionableReferrers;
    }

    /**
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return \Propel\Generator\Model\Column|null
     */
    public function getReferrerIdsColumn(ForeignKey $fk): ?Column
    {
        $fkTableName = $fk->getTable()->getName();
        $fkIdsColumnName = $fkTableName . '_ids';

        return $this->syncedTable->getColumn($fkIdsColumnName);
    }

    /**
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return \Propel\Generator\Model\Column|null
     */
    public function getReferrerVersionsColumn(ForeignKey $fk): ?Column
    {
        $fkTableName = $fk->getTable()->getName();
        $fkIdsColumnName = $fkTableName . '_versions';

        return $this->syncedTable->getColumn($fkIdsColumnName);
    }

    /**
     * @return $this|\Propel\Generator\Behavior\Versionable\VersionableBehaviorObjectBuilderModifier
     */
    public function getObjectBuilderModifier()
    {
        if ($this->objectBuilderModifier === null) {
            $this->objectBuilderModifier = new VersionableBehaviorObjectBuilderModifier($this);
        }

        return $this->objectBuilderModifier;
    }

    /**
     * @return $this|\Propel\Generator\Behavior\Versionable\VersionableBehaviorQueryBuilderModifier
     */
    public function getQueryBuilderModifier()
    {
        if ($this->queryBuilderModifier === null) {
            $this->queryBuilderModifier = new VersionableBehaviorQueryBuilderModifier($this);
        }

        return $this->queryBuilderModifier;
    }
}
