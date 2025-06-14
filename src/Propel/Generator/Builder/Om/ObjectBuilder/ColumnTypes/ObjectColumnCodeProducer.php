<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

class ObjectColumnCodeProducer extends ColumnCodeProducer
{
    /**
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    public function addColumnAttributes(string &$script): void
    {
        $this->addDefaultColumnAttribute($script, 'resource');
        $objectType = $this->getQualifiedTypeString() ?: 'object';
        $this->addColumnAttributeUnserialized($script, $objectType);
    }

    /**
     * @return string
     */
    #[\Override]
    public function getDefaultValueString(): string
    {
        $defaultValue = $this->column->getPhpDefaultValue();

        return $defaultValue !== null
            ? 'new ' . $this->column->getPhpType() . '(' . var_export($defaultValue, true) . ')'
            : 'null';
    }

    /**
     * Adds the function body for an object accessor method.
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
        $typeHint = $this->column->getTypeHint();
        $docHint = !$typeHint ? '' : "
                /** @var $typeHint \$unserializedString */";

        $script .= "
        if (!\$this->$cloUnserialized && is_resource(\$this->$clo)) {
            \$serialisedString = stream_get_contents(\$this->$clo);
            if (\$serialisedString) {{$docHint}
                \$unserializedString = unserialize(\$serialisedString);
                \$this->$cloUnserialized = \$unserializedString;
            }
        }

        return \$this->$cloUnserialized;";
    }

    /**
     * Adds a setter for Object columns.
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
        $columnConstant = $this->objectBuilder->getColumnConstant($col);

        $script .= "
        \$serializedValue = serialize(\$v);
        if (\$this->$clo === null || stream_get_contents(\$this->$clo) !== \$serializedValue) {
            \$this->$cloUnserialized = \$v;
            \$this->$clo = \$this->writeResource(\$serializedValue);
            \$this->modifiedColumns[$columnConstant] = true;
        }\n";
    }
}
