<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Builder\Om\ObjectBuilder\ObjectCodeProducer;
use Propel\Generator\Model\Column;

class ColumnCodeProducer extends ObjectCodeProducer
{
    /**
     * @var \Propel\Generator\Model\Column
     */
    protected Column $column;

    /**
     * @param \Propel\Generator\Model\Column $column
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $builder
     */
    public function __construct(Column $column, ObjectBuilder $builder)
    {
        parent::__construct($builder->getTable(), $builder);
        $this->column = $column;
    }

    /**
     * @return string
     */
    protected function getQualifiedTypeString(): string
    {
        return $this->column->resolveQualifiedType();
    }

    /**
     * Adds variables that store column values.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    public function addColumnAttributes(string &$script): void
    {
        $this->addDefaultColumnAttribute($script);
    }

    /**
     * @param string $script
     * @param string|null $columnType
     *
     * @return void
     */
    public function addDefaultColumnAttribute(string &$script, ?string $columnType = null): void
    {
        $columnType ??= $this->getQualifiedTypeString();
        $clo = $this->column->getLowercasedName();

        $script .= "
    /**
     * The value for the $clo field.{$this->getColumnDescriptionDoc()}{$this->getDefaultValueDescription()}
     *
     * @var $columnType|null
     */
    protected \${$clo };\n";
    }

    /**
     * Adds attribute for unserialized value of array and object columns.
     *
     * @param string $script
     * @param string $typeHint
     *
     * @return void
     */
    protected function addColumnAttributeUnserialized(string &$script, string $typeHint): void
    {
        $columnName = $this->column->getLowercasedName();

        $script .= "
    /**
     * The unserialized \$$columnName value - i.e. the persisted object.
     * This is necessary to avoid repeated calls to unserialize() at runtime.
     *
     * @var $typeHint|null
     */
    protected \${$columnName}_unserialized;\n";
    }

    /**
     * @return string
     */
    protected function getDefaultValueDescription(): string
    {
        $defaultValue = $this->column->getDefaultValue();
        if (!$defaultValue) {
            return '';
        }
        $defaultValueDescription = $defaultValue->isExpression()
            ? '(expression) ' . $defaultValue->getValue()
            : $this->getDefaultValueString();

        return "
     *
     * Note: this column has a database default value of: $defaultValueDescription";
    }

    /**
     * @return string
     */
    protected function getColumnDescriptionDoc(): string
    {
        $description = $this->column->getDescription();

        return !$description ? '' : "
     *
     * {$description}";
    }

    /**
     * Returns the type-casted and stringified default value for the specified
     * Column. This only works for scalar default values currently.
     *
     * @return string
     */
    public function getDefaultValueString(): string
    {
        $defaultValue = $this->column->getPhpDefaultValue();
        if ($defaultValue === null) {
            return 'null';
        }
        if ($this->column->isPhpPrimitiveType()) {
            settype($defaultValue, $this->column->getPhpType());
        }

        return var_export($defaultValue, true);
    }

    /**
     * @return string
     */
    protected function getAccessorParams(): string
    {
        return $this->column->isLazyLoad() ? '$con' : '';
    }

    /**
     * Calls methods to build getter.
     *
     * Method is final to allow decorators.
     *
     * @param string $script
     *
     * @return void
     */
    final public function addAccessor(string &$script): void
    {
        $this->addAccessorComment($script);
        $this->addAccessorOpen($script);
        $this->addAccessorBody($script);
        $this->addAccessorClose($script);
        $this->addAccessorAddition($script);
    }

    /**
     * Add the comment for a default accessor method (a getter).
     *
     * @param string $script
     * @param string $additionalParam injected from outer class (lazy load)
     *
     * @return void
     */
    protected function addAccessorComment(string &$script, string $additionalParam = ''): void
    {
        $clo = $this->column->getLowercasedName();

        $returnType = $this->getQualifiedTypeString();

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc()}{$additionalParam}
     *
     * @return {$returnType}|null
     */";
    }

    /**
     * Adds the function declaration for a default accessor.
     *
     * @param string $script
     * @param string $additionalParam injected from outer class (lazy load)
     *
     * @return void
     */
    protected function addAccessorOpen(string &$script, string $additionalParam = ''): void
    {
        $columnName = $this->column->getPhpName();
        $visibility = $this->column->getAccessorVisibility();

        $script .= "
    $visibility function get$columnName($additionalParam)
    {";
    }

    /**
     * Adds the function body for a default accessor method.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addAccessorBody(string &$script): void
    {
        $clo = $this->column->getLowercasedName();
        $script .= "
        return \$this->$clo;";
    }

    /**
     * Adds the function close for a default accessor method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAccessorClose(string &$script): void
    {
        $script .= "
    }\n";
    }

    /**
     * Hook for child classes.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAccessorAddition(string &$script): void
    {
    }

    /**
     * Calls methods to build setter.
     *
     * Method is final to allow decorators.
     *
     * @param string $script
     *
     * @return void
     */
    final public function addMutator(string &$script): void
    {
        $this->addMutatorComment($script);
        $this->addMutatorMethodHeader($script);
        $this->addMutatorBody($script);
        $this->addMutatorBodyRelationsCode($script);
        $this->addMutatorClose($script);
        $this->addMutatorAddition($script);
    }

    /**
     * Adds the comment for a mutator.
     *
     * @param string $script
     *
     * @return void
     */
    public function addMutatorComment(string &$script): void
    {
        $clo = $this->column->getLowercasedName();
        $type = $this->getQualifiedTypeString();

        if (!$this->column->isNotNull()) {
            $type .= '|null';
        }

        $script .= "
    /**
     * Set the value of [$clo] column.{$this->getColumnDescriptionDoc()}
     *
     * @param $type \$v New value
     *
     * @return \$this
     */";
    }

    /**
     * Adds the mutator function declaration.
     *
     * @param string $script
     *
     * @return void
     */
    public function addMutatorMethodHeader(string &$script): void
    {
        $columnName = $this->column->getPhpName();
        $visibility = $this->getTable()->isReadOnly() ? 'protected' : $this->column->getMutatorVisibility();

        $typeHint = '';
        $null = '';

        if ($this->column->getTypeHint()) {
            $typeHint = $this->column->getTypeHint();
            if ($typeHint !== 'array') {
                $typeHint = $this->declareClass($typeHint);
            }

            $typeHint .= ' ';

            if (!$this->column->isNotNull()) {
                $typeHint = '?' . $typeHint;
                $null = ' = null';
            }
        }

        $script .= "
    $visibility function set$columnName($typeHint\$v$null)
    {";
    }

    /**
     * Adds the mutator open body part.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addMutatorBody(string &$script): void
    {
         $clo = $this->column->getLowercasedName();

        if ($this->column->isPhpPrimitiveType()) {
            $type = $this->column->getPhpType();
            $script .= "
        if (\$v !== null) {
            \$v = ($type)\$v;
        }\n";
        }

        $columnConstant = $this->objectBuilder->getColumnConstant($this->column);
        $script .= "
        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[$columnConstant] = true;
        }\n";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addMutatorBodyRelationsCode(string &$script): void
    {
        $table = $this->getTable();
        if ($this->column->isForeignKey()) {
            foreach ($this->column->getForeignKeys() as $fk) {
                $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($this->column->getName()));

                if (!$colFK) {
                    continue;
                }
                $attributeName = $this->objectBuilder->getFKVarName($fk);

                $script .= "
        if (\$this->$attributeName !== null && \$this->{$attributeName}->get{$colFK->getPhpName()}() !== \$v) {
            \$this->$attributeName = null;
        }\n";
            }
        }

        foreach ($this->column->getReferrers() as $refFK) {
            $tblFK = $this->getDatabase()->getTable($refFK->getForeignTableName());
            if ($tblFK->getName() === $table->getName()) {
                continue;
            }
            foreach ($this->column->getForeignKeys() as $fk) {
                $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($this->column->getName()));

                if ($refFK->isLocalPrimaryKey()) {
                    $varName = $this->objectBuilder->getPKRefFKVarName($refFK);
                    $script .= "
        // update associated " . $tblFK->getPhpName() . "
        if (\$this->$varName !== null) {
            \$this->{$varName}->set" . $colFK->getPhpName() . "(\$v);
        }\n";
                } else {
                    $collName = $this->objectBuilder->getRefFKCollVarName($refFK);
                    $script .= "

        // update associated " . $tblFK->getPhpName() . "
        if (\$this->$collName !== null) {
            foreach (\$this->$collName as \$referrerObject) {
                    \$referrerObject->set" . $colFK->getPhpName() . "(\$v);
                }
            }\n";
                }
            }
        }
    }

    /**
     * Adds the close for the mutator close
     *
     * @see addMutatorClose()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addMutatorClose(string &$script): void
    {
        $script .= "
        return \$this;
    }\n";
    }

    /**
     * Hook for child classes.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addMutatorAddition(string &$script): void
    {
    }
}
