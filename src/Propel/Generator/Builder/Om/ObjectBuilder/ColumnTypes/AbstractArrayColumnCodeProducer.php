<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

/**
 * Base class for array-based column types (array and set).
 */
abstract class AbstractArrayColumnCodeProducer extends ColumnCodeProducer
{
    /**
     * Adds a tester method for an array column.
     *
     * @param string $script
     * @param string $typeDescription
     *
     * @return void
     */
    protected function addHasArrayElement(string &$script, string $typeDescription): void
    {
        $clo = $this->column->getLowercasedName();
        $cfc = $this->column->getPhpName();
        $visibility = $this->column->getAccessorVisibility();
        $singularPhpName = $this->column->getPhpSingularName();
        $script .= $this->column->isLazyLoad() ? "
    /**
     * Test the presence of a value in the [$clo] $typeDescription column value.
     *
     * @param mixed \$value
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.
     *
     * @return bool
     */
    $visibility function has$singularPhpName(\$value, ?ConnectionInterface \$con = null): bool
    {
        return in_array(\$value, \$this->get$cfc(\$con));
    }\n" : "
    /**
     * Test the presence of a value in the [$clo] $typeDescription column value.
     *
     * @param mixed \$value
     *
     * @return bool
     */
    $visibility function has$singularPhpName(\$value): bool
    {
        return in_array(\$value, \$this->get$cfc());
    }\n";
    }

    /**
     * Adds a push method for an array column.
     *
     * @param string $script
     * @param string $typeDescription
     *
     * @return void
     */
    protected function addAddArrayElement(string &$script, string $typeDescription): void
    {
        $col = $this->column;
        $clo = $col->getLowercasedName();
        $cfc = $col->getPhpName();
        $visibility = $col->getAccessorVisibility();
        $singularPhpName = $col->getPhpSingularName();
        $script .= $this->column->isLazyLoad() ? "
    /**
     * Adds a value to the [$clo] $typeDescription column value.{$this->getColumnDescriptionDoc()}
     *
     * @param mixed \$value
     * @param ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.
     *
     * @return \$this
     */
    $visibility function add$singularPhpName(\$value, ?ConnectionInterface \$con = null)
    {
        \$currentArray = \$this->get$cfc(\$con);
        \$currentArray[] = \$value;
        \$this->set$cfc(\$currentArray);

        return \$this;
    }\n" : "
    /**
     * Adds a value to the [$clo] $typeDescription column value.{$this->getColumnDescriptionDoc()}
     *
     * @param mixed \$value
     *
     * @return \$this
     */
    $visibility function add$singularPhpName(\$value)
    {
        \$currentArray = \$this->get$cfc();
        \$currentArray[] = \$value;
        \$this->set$cfc(\$currentArray);

        return \$this;
    }\n";
    }

    /**
     * Adds a remove method for an array column.
     *
     * @param string $script
     * @param string $typeDescription
     *
     * @return void
     */
    protected function addRemoveArrayElement(string &$script, string $typeDescription): void
    {
        $col = $this->column;
        $clo = $col->getLowercasedName();
        $cfc = $col->getPhpName();
        $visibility = $col->getAccessorVisibility();
        $singularPhpName = $col->getPhpSingularName();
        $script .= $this->column->isLazyLoad() ? "
    /**
     * Removes a value from the [$clo] $typeDescription column value.{$this->getColumnDescriptionDoc()}
     *
     * @param mixed \$value
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.
     *
     * @return \$this
     */
    $visibility function remove$singularPhpName(\$value, ?ConnectionInterface \$con = null)
    {
        \$targetArray = [];
        foreach (\$this->get$cfc(\$con) as \$element) {
            if (\$element != \$value) {
                \$targetArray[] = \$element;
            }
        }
        \$this->set$cfc(\$targetArray);

        return \$this;
    }\n" : "
    /**
     * Removes a value from the [$clo] $typeDescription column value.{$this->getColumnDescriptionDoc()}
     *
     * @param mixed \$value
     *
     * @return \$this
     */
    $visibility function remove$singularPhpName(\$value)
    {
        \$targetArray = [];
        foreach (\$this->get$cfc() as \$element) {
            if (\$element != \$value) {
                \$targetArray[] = \$element;
            }
        }
        \$this->set$cfc(\$targetArray);

        return \$this;
    }\n";
    }
}
