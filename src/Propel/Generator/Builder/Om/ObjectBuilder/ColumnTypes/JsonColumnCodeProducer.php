<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

class JsonColumnCodeProducer extends ColumnCodeProducer
{
 /**
  * @param string $script
  * @param string $additionalParam injected from outer class (lazy load)
  *
  * @return void
  */
    #[\Override]
    protected function addAccessorComment(string &$script, string $additionalParam = ''): void
    {
        $clo = $this->column->getLowercasedName();

        $orNull = $this->column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc()}
     *
     * @param bool \$asArray Returns the JSON data as array instead of object{$additionalParam}
     *
     * @return object|array{$orNull}
     */";
    }

    /**
     * Adds the function declaration for a JSON accessor.
     *
     * @param string $script
     * @param string $additionalParam injected from outer class (lazy load)
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorOpen(string &$script, string $additionalParam = ''): void
    {
        $cfc = $this->column->getPhpName();
        $visibility = $this->column->getAccessorVisibility();
        $maybeCon = $additionalParam ? ", $additionalParam" : '';

        $script .= "
    $visibility function get$cfc(\$asArray = true{$maybeCon})
    {";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorBody(string &$script): void
    {
        $clo = $this->column->getLowercasedName();
        $script .= "
        return json_decode(\$this->$clo, \$asArray);";
    }

    /**
     * Adds the comment for a mutator.
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
     * @param string|array|object{$orNull} \$v new value
     *
     * @return \$this
     */";
    }

    /**
     * Adds a setter for Json columns.
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
        $clo = $this->column->getLowercasedName();

        $script .= "
        if (is_string(\$v)) {
            // JSON as string needs to be decoded/encoded to get a reliable comparison (spaces, ...)
            \$v = json_decode(\$v);
        }
        \$encodedValue = json_encode(\$v);
        if (\$encodedValue !== \$this->$clo) {
            \$this->$clo = \$encodedValue;
            \$this->modifiedColumns[" . $this->objectBuilder->getColumnConstant($this->column) . "] = true;
        }\n";
    }
}
