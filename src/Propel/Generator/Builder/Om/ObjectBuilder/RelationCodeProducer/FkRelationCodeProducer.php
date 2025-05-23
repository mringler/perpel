<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes\ColumnCodeProducerFactory;
use Propel\Generator\Builder\Util\EntityObjectClassNames;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;

class FkRelationCodeProducer extends AbstractRelationCodeProducer
{
    /**
     * @var \Propel\Generator\Model\ForeignKey
     */
    protected $relation;

    /**
     * @var \Propel\Generator\Builder\Util\EntityObjectClassNames
     */
    protected EntityObjectClassNames $targetTableNames;

    /**
     * @param \Propel\Generator\Model\ForeignKey $relation
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $parentBuilder
     */
    public function __construct(ForeignKey $relation, ObjectBuilder $parentBuilder)
    {
        $this->relation = $relation;
        parent::__construct($relation->getTable(), $parentBuilder);
        $this->targetTableNames = $this->referencedClasses->useEntityObjectClassNames($relation->getForeignTable());
    }

    /**
     * Constructs variable name for fkey-related objects.
     *
     * @return string
     */
    public function getAttributeName(): string
    {
        return 'a' . $this->relation->getIdentifier();
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addMethods(string &$script): void
    {
        $this->addMutator($script);
        $this->addAccessor($script);
    }

    /**
     * Get target class data, possibly intersected with 'interface' attribute declared on foreign-key tag in schema.xml.
     *
     * @return array{string, string}
     */
    protected function getTargetClassNameOrInterface(): array
    {
        $className = $this->targetTableNames->useObjectBaseClassName();
        $classNameFq = $this->targetTableNames->useObjectBaseClassName(false);

        /*
        $interface = $this->relation->getInterface();
        if ($interface) {
            $className .= '&' . $this->declareClass($interface);
            $classNameFq .= '&\\' . trim($interface, '\\');
        }
        */

        return [$className, $classNameFq];
    }

    /**
     * Adds the class attributes that are needed to store fkey related objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    public function addAttributes(string &$script): void
    {
        $className = $this->targetTableNames->useObjectBaseClassName();
        $classNameFq = $this->targetTableNames->useObjectBaseClassName(false);

        $varName = '$' . $this->getAttributeName();

        $script .= "
    /**
     * @var $classNameFq|null
     */
    protected ?$className $varName = null;\n";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addOnReloadCode(string &$script): void
    {
        $varName = $this->getAttributeName();
        $script .= "
            \$this->" . $varName . ' = null;';
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addDeleteScheduledItemsCode(string &$script): void
    {
        $isReadOnly = $this->relation->getForeignTable()->isReadOnly();

        $attributeName = $this->getAttributeName();
        $relationIdentifierSingular = $this->relation->getIdentifier();

        $maybeWriteTarget = $isReadOnly ? '' : "
            if (\$this->{$attributeName}->isModified() || \$this->{$attributeName}->isNew()) {
                \$affectedRows += \$this->{$attributeName}->save(\$con);
            }";

        $script .= "
        if (\$this->$attributeName !== null) {{$maybeWriteTarget}
            \$this->set{$relationIdentifierSingular}(\$this->$attributeName);
        }\n";
    }

    /**
     * @param string $script
     *
     * @return string
     */
    #[\Override]
    public function addClearReferencesCode(string &$script): string
    {
        $varName = $this->getAttributeName();
        $script .= "
        \$this->$varName = null;";

        return $varName;
    }

    /**
     * Adds the mutator (setter) method for setting an fkey related object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addMutator(string &$script): void
    {
        $relationIdentifierSingular = $this->relation->getIdentifier();
        $varName = '$' . lcfirst($relationIdentifierSingular);
        $reverseIdentifierSingular = $this->relation->getIdentifierReversed();

        [$targetClassName, $targetType] = $this->getTargetClassNameOrInterface();

        $attributeName = $this->getAttributeName();
        $setAdd = $this->relation->isLocalPrimaryKey() ? 'set' : 'add'; // one-to-one or one-to-many

        $script .= "
    /**
     * Declares an association between this object and a $targetClassName object.
     *
     * @param {$targetType}|null $varName
     *
     * @return \$this
     */
    public function set{$relationIdentifierSingular}(?$targetClassName $varName = null)
    {";

        foreach ($this->relation->getMapping() as $map) {
            [$column, $rightValueOrColumn] = $map;
            $columnName = $column->getPhpName();
            $valueVarName = '$' . lcfirst($columnName);

            if ($rightValueOrColumn instanceof Column) {
                $defaultValue = ColumnCodeProducerFactory::create($column, $this->objectBuilder)->getDefaultValueString();
                $getterIdentifier = $rightValueOrColumn->getPhpName();
                $val = "{$varName}->get{$getterIdentifier}()";
            } else {
                $defaultValue = 'null';
                $val = var_export($rightValueOrColumn, true);
            }
            $script .= "
        $valueVarName = $varName ? $val : $defaultValue;
        \$this->set{$columnName}($valueVarName);";
        }

        $script .= "

        \$this->$attributeName = $varName;
        {$varName}?->{$setAdd}{$reverseIdentifierSingular}(\$this);

        return \$this;
    }\n";
    }

    /**
     * Adds the accessor (getter) method for getting an fkey related object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAccessor(string &$script): void
    {
        $fk = $this->relation;
        $varName = $this->getAttributeName();
        $relationIdentifierSingular = $fk->getIdentifier();

        $relationIdentifierReversedSingular = $fk->getIdentifierReversed();
        $relationIdentifierReversedPlural = $fk->getIdentifierReversed($this->getPluralizer());
        [$_, $targetType] = $this->getTargetClassNameOrInterface();

        // If the related columns are a primary key on the foreign table
        // then use findPk() instead of doSelect() to take advantage
        // of instance pooling
        $findPk = $fk->isForeignPrimaryKey();
        $valueIsEmpty = $this->buildPropertyIsNotEmptyConditionExpression($fk);
        $targetGetPkArgs = $this->buildTargetGetPkArgs($fk);

        $targetQueryClassName = $this->targetTableNames->useQueryStubClassName();

        $script .= "
    /**
     * Get the associated $relationIdentifierSingular object
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con Optional Connection object.
     *
     * @return {$targetType}|null
     */
    public function get{$relationIdentifierSingular}(?ConnectionInterface \$con = null)
    {";
        $script .= "
        if (\$this->$varName === null && ($valueIsEmpty)) {";
        if ($findPk) {
            $script .= "
            \$this->$varName = {$targetQueryClassName}::create()->findPk($targetGetPkArgs, \$con);";
        } else {
            $script .= "
            \$this->$varName = {$targetQueryClassName}::create()
                ->filterBy{$relationIdentifierReversedSingular}(\$this) // here
                ->findOne(\$con);";
        }
        if ($fk->isLocalPrimaryKey()) {
            $script .= "
            // Because this foreign key represents a one-to-one relationship, we will create a bi-directional association.
            \$this->{$varName}->set{$relationIdentifierReversedSingular}(\$this);";
        } else {
            $script .= "
            /* The following can be used additionally to
                guarantee the related object contains a reference
                to this object.  This level of coupling may, however, be
                undesirable since it could result in an only partially populated collection
                in the referenced object.
                \$this->{$varName}->add{$relationIdentifierReversedPlural}(\$this);
             */";
        }

        $script .= "
        }

        return \$this->$varName;
    }
";
    }

    /**
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    protected function buildPropertyIsNotEmptyConditionExpression(ForeignKey $fk): string
    {
        $conditions = [];

        foreach ($fk->getMapping() as $mapping) {
            [$column, $rightValueOrColumn] = $mapping;

            $cptype = $column->getPhpType();
            $clo = $column->getLowercasedName();

            if ($rightValueOrColumn instanceof Column) {
                if (in_array($cptype, ['int', 'float', 'double'])) {
                    $conditions[] = "\$this->$clo !== null && \$this->$clo !== 0";
                } elseif ($cptype === 'string') {
                    $conditions[] = "\$this->$clo !== '' && \$this->$clo !== null";
                } else {
                    $conditions[] = "\$this->$clo !== null";
                }
            } else {
                $val = var_export($rightValueOrColumn, true);
                $conditions[] = "\$this->$clo === $val";
            }
        }

        return implode(' && ', $conditions);
    }

    /**
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    protected function buildTargetGetPkArgs(ForeignKey $fk): string
    {
        $localColumns = [];

        foreach ($fk->getMapping() as $mapping) {
            [$column, $rightValueOrColumn] = $mapping;

            if (!$rightValueOrColumn instanceof Column) {
                continue;
            }
            $clo = $column->getLowercasedName();
            $localColumns[$rightValueOrColumn->getPosition()] = '$this->' . $clo;
        }

        ksort($localColumns); // restoring the order of the foreign PK

        return count($localColumns) === 1
            ? reset($localColumns)
            : '[' . implode(', ', $localColumns) . ']';
    }
}
