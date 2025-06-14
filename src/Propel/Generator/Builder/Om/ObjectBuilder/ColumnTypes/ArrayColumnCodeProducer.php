<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

class ArrayColumnCodeProducer extends AbstractArrayColumnCodeProducer
{
    /**
     * @return string
     */
    #[\Override]
    protected function getQualifiedTypeString(): string
    {
        return 'array';
    }

    /**
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    public function addColumnAttributes(string &$script): void
    {
        $this->addDefaultColumnAttribute($script, 'string');
        $this->addColumnAttributeUnserialized($script, 'array');
    }

    /**
     * @return string
     */
    #[\Override]
    public function getDefaultValueString(): string
    {
        $defaultValue = $this->column->getPhpDefaultValue();

        return $defaultValue !== null
            ? var_export($defaultValue, true)
            : 'null';
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addAccessorAddition(string &$script): void
    {
        if ($this->column->isNamePlural()) {
            $this->addHasArrayElement($script, 'array');
        }
    }

    /**
     * Adds the function body for an array accessor method.
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorBody(string &$script): void
    {
        $clo = $this->column->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';

        $script .= "
        if (\$this->$cloUnserialized === null) {
            \$this->$cloUnserialized = [];
        }
        if (!\$this->$cloUnserialized && \$this->$clo !== null) {
            \$$cloUnserialized = substr(\$this->$clo, 2, -2);
            \$this->$cloUnserialized = \$$cloUnserialized !== '' ? explode(' | ', \$$cloUnserialized) : [];
        }

        return \$this->$cloUnserialized;";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addMutatorAddition(string &$script): void
    {
        if ($this->column->isNamePlural()) {
            $this->addAddArrayElement($script, 'array');
            $this->addRemoveArrayElement($script, 'array');
        }
    }

    /**
     * Adds a setter for Array columns.
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
        $cloUnserialized = $clo . '_unserialized';

        $script .= "
        if (\$this->$cloUnserialized !== \$v) {
            \$this->$cloUnserialized = \$v;
            \$this->$clo = '| ' . implode(' | ', \$v) . ' |';
            \$this->modifiedColumns[" . $this->objectBuilder->getColumnConstant($col) . "] = true;
        }\n";
    }
}
