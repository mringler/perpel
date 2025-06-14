<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

use Propel\Common\Util\SetColumnConverter;

class SetColumnCodeProducer extends AbstractArrayColumnCodeProducer
{
    /**
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    public function addColumnAttributes(string &$script): void
    {
        $this->addDefaultColumnAttribute($script, 'int');
        $this->addColumnAttributeConvertedDeclaration($script);
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addColumnAttributeConvertedDeclaration(string &$script): void
    {
        $attributeName = '$' . $this->column->getLowercasedName() . '_converted';
        $script .= "
    /**
     * @var array<string>|null
     */
    protected ?array $attributeName = null;\n";
    }

    /**
     * @return string
     */
    #[\Override]
    public function getDefaultValueString(): string
    {
        $defaultValue = $this->column->getPhpDefaultValue();

        return $defaultValue === null
            ? 'null'
            : (string)SetColumnConverter::convertToInt($defaultValue, $this->column->getValueSet());
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
            $this->addHasArrayElement($script, 'set');
        }
    }

    /**
     * Add the comment for a SET column accessor method.
     *
     * @param string $script
     * @param string $additionalParam injected from outer class (lazy load)
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
     * @return array|null
     */";
    }

    /**
     * Adds the function body for a SET column accessor method.
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorBody(string &$script): void
    {
        $this->declareClasses(
            'Propel\Common\Util\SetColumnConverter',
            'Propel\Common\Exception\SetColumnConverterException',
        );

        $clo = $this->column->getLowercasedName();
        $cloConverted = $clo . '_converted';

        $tableMapClassName = $this->getTableMapClassName();
        $columnConstantExpression = $this->objectBuilder->getColumnConstant($this->column);

        $script .= "
        if (\$this->$cloConverted === null) {
            \$this->$cloConverted = [];
        }
        if (!\$this->$cloConverted && \$this->$clo !== null) {
            \$valueSet = {$tableMapClassName}::getValueSet($columnConstantExpression);
            try {
                \$this->$cloConverted = SetColumnConverter::convertIntToArray(\$this->$clo, \$valueSet);
            } catch (SetColumnConverterException \$e) {
                throw new PropelException('Unknown stored set key: ' . \$e->getValue(), \$e->getCode(), \$e);
            }
        }

        return \$this->$cloConverted;";
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
            $this->addAddArrayElement($script, 'set');
            $this->addRemoveArrayElement($script, 'set');
        }
    }

    /**
     * Adds the comment for a SET column mutator.
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
     * @param array{$orNull} \$v new value
     *
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     *
     * @return \$this
     */";
    }

    /**
     * Adds a setter for SET column mutator.
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
        $cloConverted = $clo . '_converted';

        $this->declareClasses(
            'Propel\Common\Util\SetColumnConverter',
            'Propel\Common\Exception\SetColumnConverterException',
        );

        $script .= "
        if (\$this->$cloConverted === null || count(array_diff(\$this->$cloConverted, \$v)) > 0 || count(array_diff(\$v, \$this->$cloConverted)) > 0) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->objectBuilder->getColumnConstant($col) . ");
            try {
                \$v = SetColumnConverter::convertToInt(\$v, \$valueSet);
            } catch (SetColumnConverterException \$e) {
                throw new PropelException(sprintf('Value \"%s\" is not accepted in this set column', \$e->getValue()), \$e->getCode(), \$e);
            }
            if (\$this->$clo !== \$v) {
                \$this->$cloConverted = null;
                \$this->$clo = \$v;
                \$this->modifiedColumns[" . $this->objectBuilder->getColumnConstant($col) . "] = true;
            }
        }\n";
    }
}
