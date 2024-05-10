<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\SyncedTable;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\Table;

/**
 * Adds empty getter and setter for the given columns.
 *
 * Used in SyncedTableBehavior to keep generated model classes of the synced
 * table compatible with the source table if ignore_columns is used.
 */
class EmptyColumnAccessorsBehavior extends Behavior
{
    /**
     * @var string
     */
    public const PARAMETER_KEY_COLUMNS = 'columns';

    /**
     * Add this behavior to a table.
     *
     * @param \Propel\Generator\Model\Table $table
     * @param string $columnsCSV
     *
     * @return \Propel\Generator\Behavior\SyncedTable\EmptyColumnAccessorsBehavior
     */
    public static function addToTable(Table $table, string $columnsCSV): self
    {
        $emptyAccessorsBehavior = new self();
        $emptyAccessorsBehavior->setId('empty_column_accessors');
        $emptyAccessorsBehavior->setDatabase($table->getDatabase());
        $emptyAccessorsBehavior->setTable($table);
        $emptyAccessorsBehavior->setParameters([
            self::PARAMETER_KEY_COLUMNS => $columnsCSV,
        ]);
        $table->addBehavior($emptyAccessorsBehavior);

        return $emptyAccessorsBehavior;
    }

    /**
     * @return array<string>
     */
    protected function getColumnNames(): array
    {
        return $this->getDefaultValueForSet($this->getParameter(static::PARAMETER_KEY_COLUMNS) ?? '') ?? [];
    }

    /**
     * @return array<string>
     */
    protected function buildAccessorNames(): array
    {
        $mockedColumnNames = $this->getColumnNames();
        $accessors = [];
        foreach ($mockedColumnNames as $columnName) {
            $phpName = (new Column($columnName))->getPhpName();
            array_push($accessors, 'get' . $phpName, 'set' . $phpName);
        }

        return $accessors;
    }

    /**
     * @see \Propel\Generator\Model\Behavior::objectAttributes()
     *
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $builder
     *
     * @return string
     */
    public function objectAttributes(ObjectBuilder $builder): string
    {
        $accessorNames = $this->buildAccessorNames();
        if (!$accessorNames) {
            return '';
        }
        $nameToArrayKeyFun = fn (string $name) => "    '$name' => 1,";
        $namesAsArrayKeys = implode("\n", array_map($nameToArrayKeyFun, $accessorNames));

        return <<<EOT
/**
 * Non-existing columns with mock getters and setters.
 * 
 * Calls to these accessors will be handled in __call().
 */
protected const MOCKED_ACCESSORS = [
$namesAsArrayKeys
];
EOT;
    }

    /**
     * @see \Propel\Generator\Model\Behavior::objectCall()
     *
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $builder
     *
     * @return string
     */
    public function objectCall(ObjectBuilder $builder): string
    {
        return <<< EOT
    if (!empty(static::MOCKED_ACCESSORS[\$name])){
        try {
            return \$this->__parentCall(\$name, \$params);
        } catch(BadMethodCallException \$e){
            return null;
        }
    }

EOT;
    }
}
