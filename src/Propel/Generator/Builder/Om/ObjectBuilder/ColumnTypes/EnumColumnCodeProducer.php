<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

use Propel\Generator\Exception\EngineException;

class EnumColumnCodeProducer extends ColumnCodeProducer
{
    /**
     * @throws \Propel\Generator\Exception\EngineException
     *
     * @return string
     */
    #[\Override]
    public function getDefaultValueString(): string
    {
        $defaultValue = $this->column->getPhpDefaultValue();
        if ($defaultValue === null) {
            return 'null';
        }

        $valueSet = $this->column->getValueSet();
        if (!in_array($defaultValue, $valueSet)) {
            throw new EngineException(sprintf('Default Value "%s" is not among the enumerated values', $defaultValue));
        }

        return (string)array_search($defaultValue, $valueSet);
    }

    /**
     * Add the comment for an enum accessor method.
     *
     * @param string $script
     * @param string $additionalParam
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorComment(string &$script, string $additionalParam = ''): void
    {
        $clo = $this->column->getLowercasedName();

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc()}{$additionalParam}
     *
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     *
     * @return string|null
     */";
    }

    /**
     * Adds the function body for an enum accessor method.
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorBody(string &$script): void
    {
        $clo = $this->column->getLowercasedName();

        $script .= "
        if (\$this->$clo === null) {
            return null;
        }
        \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->objectBuilder->getColumnConstant($this->column) . ");
        if (!isset(\$valueSet[\$this->$clo])) {
            throw new PropelException('Unknown stored enum key: ' . \$this->$clo);
        }

        return \$valueSet[\$this->$clo];";
    }

    /**
     * Adds the comment for an enum mutator.
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addMutatorComment(string &$script): void
    {
        $clo = $this->column->getLowercasedName();
        $orNull = $this->column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.{$this->getColumnDescriptionDoc()}
     *
     * @param string{$orNull} \$v new value
     *
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     *
     * @return \$this
     */";
    }

    /**
     * Adds a setter for Enum columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    protected function addMutatorBody(string &$script): void
    {
        $col = $this->column;
        $clo = $col->getLowercasedName();

        $script .= "
        if (\$v !== null) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->objectBuilder->getColumnConstant($col) . ");
            \$keyId = array_search(\$v, \$valueSet);
            if (!is_int(\$keyId)) {
                throw new PropelException(sprintf('Value \"%s\" is not accepted in this enumerated column', \$v));
            }
            \$v = \$keyId;
        }

        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->objectBuilder->getColumnConstant($col) . "] = true;
        }\n";
    }
}
