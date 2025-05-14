<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om;

use DateTime;
use Exception;
use Propel\Common\Util\SetColumnConverter;
use Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer\AbstractIncomingRelationCode;
use Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer\AbstractManyToManyCodeProducer;
use Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer\FkRelationCodeProducer;
use Propel\Generator\Config\GeneratorConfigInterface;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\IdMethod;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\MssqlPlatform;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\OraclePlatform;
use Propel\Generator\Platform\PlatformInterface;
use Propel\Generator\Platform\SqlsrvPlatform;
use Propel\Runtime\Exception\PropelException;

/**
 * Generates a base Object class for user object model (OM).
 *
 * This class produces the base object class (e.g. BaseMyTable) which contains
 * all the custom-built accessor and setter methods.
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
class ObjectBuilder extends AbstractObjectBuilder
{
    /**
     * @var array<\Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer\FkRelationCodeProducer>
     */
    protected $fkRelationCodeProducers = [];

    /**
     * @var array<\Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer\AbstractIncomingRelationCode>
     */
    protected $incomingRelationCodeProducers = [];

    /**
     * @var array<\Propel\Generator\Builder\Om\ObjectBuilder\RelationCodeProducer\AbstractManyToManyCodeProducer>
     */
    protected $crossRelationCodeProducers = [];

    /**
     * @param \Propel\Generator\Model\Table $table
     */
    public function __construct(Table $table)
    {
        parent::__construct($table);
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     * @param \Propel\Generator\Config\GeneratorConfigInterface|null $generatorConfig
     *
     * @return void
     */
    #[\Override]
    protected function init(Table $table, ?GeneratorConfigInterface $generatorConfig): void
    {
        parent::init($table, $generatorConfig);

        $this->fkRelationCodeProducers = [];
        $this->incomingRelationCodeProducers = [];
        $this->crossRelationCodeProducers = [];

        if (!$generatorConfig) {
            return;
        }

        foreach ($table->getForeignKeys() as $fk) {
            $this->fkRelationCodeProducers[] = new FkRelationCodeProducer($fk, $this);
        }

        foreach ($table->getReferrers() as $fk) {
            $this->incomingRelationCodeProducers[] = AbstractIncomingRelationCode::create($fk, $this);
        }

        foreach ($table->getCrossRelations() as $crossFk) {
            $this->crossRelationCodeProducers[] = AbstractManyToManyCodeProducer::create($crossFk, $this);
        }
    }

    /**
     * Returns the package for the base object classes.
     *
     * @return string
     */
    #[\Override]
    public function getPackage(): string
    {
        return parent::getPackage() . '.Base';
    }

    /**
     * Returns the namespace for the base class.
     *
     * @see \Propel\Generator\Builder\Om\AbstractOMBuilder::getNamespace()
     *
     * @return string|null
     */
    #[\Override]
    public function getNamespace(): ?string
    {
        $namespace = parent::getNamespace();

        return $namespace ? "$namespace\\Base" : 'Base';
    }

    /**
     * Returns default key type.
     *
     * If not presented in configuration default will be 'TYPE_PHPNAME'
     *
     * @return string
     */
    public function getDefaultKeyType(): string
    {
        $defaultKeyType = $this->getBuildProperty('generator.objectModel.defaultKeyType') ?: 'phpName';

        return 'TYPE_' . strtoupper($defaultKeyType);
    }

    /**
     * Returns the name of the current class being built.
     *
     * @return string
     */
    #[\Override]
    public function getUnprefixedClassName(): string
    {
        return $this->getStubObjectBuilder()->getUnprefixedClassName();
    }

    /**
     * Validates the current table to make sure that it won't result in
     * generated code that will not parse.
     *
     * This method may emit warnings for code which may cause problems
     * and will throw exceptions for errors that will definitely cause
     * problems.
     *
     * @throws \Propel\Generator\Exception\EngineException
     *
     * @return void
     */
    #[\Override]
    protected function validateModel(): void
    {
        parent::validateModel();

        $table = $this->getTable();

        // Check to see whether any generated foreign key names
        // will conflict with column names.

        $columnNames = [];
        $relationIdentifiers = [];

        foreach ($table->getColumns() as $col) {
            $columnNames[] = $col->getPhpName();
        }

        foreach ($table->getForeignKeys() as $fk) {
            $relationIdentifiers[] = $fk->getIdentifier();
        }

        foreach ($table->getReferrers() as $fk) {
            $relationIdentifiers[] = $fk->getIdentifierReversed();
        }

        $intersect = array_intersect($columnNames, $relationIdentifiers);
        if ($intersect) {
            throw new EngineException('One or more of your column names for [' . $table->getName() . '] table conflict with foreign key names (' . implode(', ', $intersect) . ')');
        }

        // Check foreign keys to see if there are any foreign keys that
        // are also matched with an inversed referencing foreign key
        // (this is currently unsupported behavior)
        // see: http://propel.phpdb.org/trac/ticket/549

        foreach ($table->getForeignKeys() as $fk) {
            if ($fk->isMatchedByInverseFK()) {
                throw new EngineException(sprintf('The 1:1 relationship expressed by foreign key %s is defined in both directions; Propel does not currently support this (if you must have both foreign key constraints, consider adding this constraint with a custom SQL file.)', $fk->getName()));
            }
        }

        // check names from cross relations
        foreach ($this->crossRelationCodeProducers as $crossRelationProducer) {
            $reservedNames = $crossRelationProducer->reserveNamesForGetters();
            foreach ($reservedNames as $crossRelationName) {
                if (in_array($crossRelationName, $columnNames)) {
                    $message = "Cross relation on table '{$table->getName()}' uses names which conflict with a column defined in the table.\n"
                        . " - Column name: '{$crossRelationName}'\n - {$crossRelationProducer->getCrossRelation()->__toString()}\n"
                        . 'You can rename the cross relation by setting a `phpName` attribute on the foreign key between middle table and target table.';

                    throw new EngineException($message);
                }
                if (in_array($crossRelationName, $relationIdentifiers)) {
                    $message = "Cross relation on table '{$table->getName()}' uses names which conflict with a relation to or from this table.\n"
                        . " - Foreign key name: '{$crossRelationName}'\n - {$crossRelationProducer->getCrossRelation()->__toString()}\n"
                        . 'You can rename the cross relation by setting a `phpName` attribute on the foreign key between middle table and target table.';

                    throw new EngineException($message);
                }
            }
        }
    }

    /**
     * Returns the appropriate formatter (from platform) for a date/time column.
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string|null
     */
    public function getTemporalFormatter(Column $column): ?string
    {
        switch ($column->getType()) {
            case PropelTypes::DATE:
                return $this->getPlatformOrFail()->getDateFormatter();
            case PropelTypes::TIME:
                return $this->getPlatformOrFail()->getTimeFormatter();
            case PropelTypes::TIMESTAMP:
            case PropelTypes::DATETIME:
                return $this->getPlatformOrFail()->getTimestampFormatter();
            default:
                return null;
        }
    }

    /**
     * Returns the type-casted and stringified default value for the specified
     * Column. This only works for scalar default values currently.
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @throws \Propel\Generator\Exception\EngineException
     *
     * @return string
     */
    public function getDefaultValueString(Column $column): string
    {
        $defaultValue = 'null';
        $val = $column->getPhpDefaultValue();
        if ($val === null) {
            return $defaultValue;
        }

        if ($column->isTemporalType()) {
            $fmt = $this->getTemporalFormatter($column);
            try {
                if (
                    !($this->getPlatform() instanceof MysqlPlatform &&
                    ($val === '0000-00-00 00:00:00' || $val === '0000-00-00'))
                ) {
                    // while technically this is not a default value of NULL,
                    // this seems to be closest in meaning.
                    $defDt = new DateTime($val);
                    $defaultValue = var_export($defDt->format((string)$fmt), true);
                }
            } catch (Exception $exception) {
                // prevent endless loop when timezone is undefined
                date_default_timezone_set('America/Los_Angeles');

                throw new EngineException(sprintf('Unable to parse default temporal value "%s" for column "%s"', $column->getDefaultValueString(), $column->getFullyQualifiedName()), 0, $exception);
            }
        } elseif ($column->isEnumType()) {
            $valueSet = $column->getValueSet();
            if (!in_array($val, $valueSet)) {
                throw new EngineException(sprintf('Default Value "%s" is not among the enumerated values', $val));
            }
            $defaultValue = (string)array_search($val, $valueSet);
        } elseif ($column->isSetType()) {
            $defaultValue = (string)SetColumnConverter::convertToInt($val, $column->getValueSet());
        } elseif ($column->isPhpPrimitiveType()) {
            settype($val, $column->getPhpType());
            $defaultValue = var_export($val, true);
        } elseif ($column->isPhpObjectType()) {
            $defaultValue = 'new ' . $column->getPhpType() . '(' . var_export($val, true) . ')';
        } elseif ($column->isPhpArrayType()) {
            $defaultValue = var_export($val, true);
        } else {
            throw new EngineException('Cannot get default value string for ' . $column->getFullyQualifiedName());
        }

        return $defaultValue;
    }

    /**
     * Return the parent class name, or null.
     *
     * @return string|null
     */
    protected function getParentClass(): ?string
    {
        $parentClass = $this->getBehaviorContent('parentClass');
        if ($parentClass !== null) {
            return $parentClass;
        }

        return ClassTools::classname($this->getBaseClass());
    }

    /**
     * Adds class phpdoc comment and opening of class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    protected function addClassOpen(string &$script): void
    {
        $table = $this->getTable();
        $tableName = $table->getName();
        $tableDesc = $table->getDescription();

        $parentClass = $this->getParentClass();
        if ($parentClass !== null) {
            $parentClass = ' extends ' . $parentClass;
        }
        $interface = $this->getInterface();
        if ($interface) {
            if ($interface !== ClassTools::classname($interface)) {
                $this->declareClass($interface);
            } else {
                $this->declareClassFromBuilder($this->getInterfaceBuilder());
            }
        }

        if ($this->getBuildProperty('generator.objectModel.addClassLevelComment')) {
            $script .= "
/**
 * Base class that represents a row from the '$tableName' table.";

            if ($tableDesc) {
                $script .= "
 *
 * $tableDesc";
            }

            if ($this->getBuildProperty('generator.objectModel.addTimeStamp')) {
                $now = strftime('%c');
                $script .= "
 *
 * This class was autogenerated by Propel {$this->getBuildProperty('general.version')} on:
 *
 * $now
 *";
            }
            $script .= "
 *
 * @package propel.generator.{$this->getPackage()}
 */";
        }

        $script .= "
abstract class {$this->getUnqualifiedClassName()}$parentClass implements ActiveRecordInterface";

        if ($interface) {
            $script .= ', Child' . ClassTools::classname($interface);
        }

        $script .= "
{";
    }

    /**
     * Specifies the methods that are added as part of the basic OM class.
     * This can be overridden by subclasses that wish to add more methods.
     *
     * @see \Propel\Generator\Builder\Om\AbstractOMBuilder::addClassBody()
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addClassBody(string &$script): void
    {
        $this->declareClassFromBuilder($this->getStubObjectBuilder());
        $this->declareClassFromBuilder($this->getStubQueryBuilder());
        $this->declareClassFromBuilder($this->getTableMapBuilder());

        $this->declareClasses(
            'Exception',
            'PDO',
            '\Propel\Runtime\Exception\PropelException',
            '\Propel\Runtime\Connection\ConnectionInterface',
            '\Propel\Runtime\Collection\ObjectCombinationCollection',
            '\Propel\Runtime\Exception\BadMethodCallException',
            '\Propel\Runtime\Exception\PropelException',
            '\Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\LocalColumnExpression',
            '\Propel\Runtime\ActiveQuery\Criteria',
            '\Propel\Runtime\ActiveQuery\ModelCriteria',
            '\Propel\Runtime\ActiveRecord\ActiveRecordInterface',
            '\Propel\Runtime\Parser\AbstractParser',
            '\Propel\Runtime\Propel',
            '\Propel\Runtime\Map\TableMap',
        );

        $baseClass = $this->getBaseClass();
        if ($baseClass && strrpos($baseClass, '\\') !== false) {
            $this->declareClasses($baseClass);
        }

        $table = $this->getTable();

        $additionalModelClasses = $table->getAdditionalModelClassImports();
        if ($additionalModelClasses) {
            $this->declareClasses(...$additionalModelClasses);
        }

        if (!$table->isAlias()) {
            $this->addConstants($script);
            $this->addAttributes($script);
        }

        foreach ($this->crossRelationCodeProducers as $producer) {
            $producer->addScheduledForDeletionAttribute($script);
        }
        foreach ($this->incomingRelationCodeProducers as $producer) {
            $producer->addScheduledForDeletionAttribute($script);
        }

        if ($this->hasDefaultValues()) {
            $this->addApplyDefaultValues($script);
        }
        $this->addConstructor($script);

        $this->addBaseObjectMethods($script);

        $this->addColumnAccessorMethods($script);
        $this->addColumnMutatorMethods($script);

        if (array_any($table->getColumns(), fn (Column $col) => $col->isLobType())) {
            $this->addWriteResource($script);
        }

        $this->addHasOnlyDefaultValues($script);

        $this->addHydrate($script);
        $this->addEnsureConsistency($script);

        if (!$table->isReadOnly()) {
            $this->addManipulationMethods($script);
        }

        if ($this->isAddGenericAccessors()) {
            $this->addGetByName($script);
            $this->addGetByPosition($script);
            $this->addToArray($script);
        }

        if ($this->isAddGenericMutators()) {
            $this->addSetByName($script);
            $this->addSetByPosition($script);
            $this->addFromArray($script);
            $this->addImportFrom($script);
        }

        $this->addBuildCriteria($script);
        $this->addBuildPkeyCriteria($script);
        $this->addHashCode($script);
        $this->addGetPrimaryKey($script);
        $this->addSetPrimaryKey($script);
        $this->addIsPrimaryKeyNull($script);

        $this->addCopy($script);

        foreach ($this->fkRelationCodeProducers as $producer) {
            $producer->addMethods($script);
        }

        AbstractIncomingRelationCode::addInitRelations($script, $table->getReferrers(), $this->getPluralizer());
        foreach ($this->incomingRelationCodeProducers as $producer) {
            $producer->addMethods($script);
        }

        foreach ($this->crossRelationCodeProducers as $producer) {
            $producer->addMethods($script);
        }

        $this->addClear($script);
        $this->addClearAllReferences($script);

        $this->addPrimaryString($script);

        // apply behaviors
        $this->applyBehaviorModifier('objectMethods', $script, '    ');

        if ($this->getBuildProperty('generator.objectModel.addHooks')) {
            $this->addHookMethods($script);
        }

        $this->addMagicCall($script);
    }

    /**
     * Closes class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    #[\Override]
    protected function addClassClose(string &$script): void
    {
        $script .= "}
";
        $this->applyBehaviorModifier('objectFilter', $script, '');
    }

    /**
     * Adds any constants to the class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addConstants(string &$script): void
    {
        $script .= "
    /**
     * TableMap class name
     *
     * @var string
     */
    public const TABLE_MAP = '" . addslashes($this->getTableMapBuilder()->getFullyQualifiedClassName()) . "';";
    }

    /**
     * Adds class attributes.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAttributes(string &$script): void
    {
        $table = $this->getTable();

        $script .= "
";

        $script .= $this->renderTemplate('baseObjectAttributes');

        if (!$table->isAlias()) {
            $this->addColumnAttributes($script);
        }

        foreach ($this->fkRelationCodeProducers as $producer) {
            $producer->addAttributes($script);
        }

        foreach ($this->incomingRelationCodeProducers as $producer) {
            $producer->addAttributes($script);
        }

        foreach ($this->crossRelationCodeProducers as $producer) {
            $producer->addAttributes($script);
        }

        $this->addAlreadyInSaveAttribute($script);

        // apply behaviors
        $this->applyBehaviorModifier('objectAttributes', $script, '    ');
    }

    /**
     * Adds variables that store column values.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addColumnAttributes(string &$script): void
    {
        $table = $this->getTable();

        foreach ($table->getColumns() as $col) {
            $this->addColumnAttributeComment($script, $col);
            $this->addColumnAttributeDeclaration($script, $col);
            if ($col->isLazyLoad()) {
                $this->addColumnAttributeLoaderComment($script, $col);
                $this->addColumnAttributeLoaderDeclaration($script, $col);
            }
            if ($col->getType() == PropelTypes::OBJECT || $col->getType() == PropelTypes::PHP_ARRAY) {
                $this->addColumnAttributeUnserialized($script, $col);
            }
            if ($col->isSetType()) {
                $this->addColumnAttributeConvertedDeclaration($script, $col);
            }
        }
    }

    /**
     * Adds comment about the attribute (variable) that stores column values.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addColumnAttributeComment(string &$script, Column $column): void
    {
        if ($column->isTemporalType()) {
            $columnType = $this->getDateTimeClass($column);
        } elseif ($column->getType() === PropelTypes::PHP_ARRAY) {
            $columnType = 'string';
        } else {
            $columnType = $column->getPhpType() ?: 'mixed';
        }

        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * The value for the $clo field.{$this->getColumnDescriptionDoc($column)}";
        if ($column->getDefaultValue()) {
            $defaultValueDescription = $column->getDefaultValue()->isExpression()
                ? '(expression) ' . $column->getDefaultValue()->getValue()
                : $this->getDefaultValueString($column);
            $script .= "
     *
     * Note: this column has a database default value of: $defaultValueDescription";
        }
        $script .= "
     *
     * @var $columnType|null
     */";
    }

    /**
     * Adds the declaration of a column value storage attribute.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addColumnAttributeDeclaration(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    protected \$" . $clo . ";
";
    }

    /**
     * Adds the comment about the attribute keeping track if an attribute value
     * has been loaded.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addColumnAttributeLoaderComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    /**
     * Whether the lazy-loaded \$$clo value has been loaded from database.
     * This is necessary to avoid repeated lookups if \$$clo column is NULL in the db.
     *
     * @var bool
     */";
    }

    /**
     * Adds the declaration of the attribute keeping track of an attribute
     * loaded state.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addColumnAttributeLoaderDeclaration(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
    protected \$" . $clo . "_isLoaded = false;
";
    }

    /**
     * Adds the comment about the serialized attribute.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addColumnAttributeUnserialized(string &$script, Column $column): void
    {
        $columnName = $column->getLowercasedName();
        $type = $column->getTypeHint() ?: ($column->getType() == PropelTypes::PHP_ARRAY ? 'array' : 'object');

        $script .= "
    /**
     * The unserialized \$$columnName value - i.e. the persisted object.
     * This is necessary to avoid repeated calls to unserialize() at runtime.
     *
     * @var $type|null
     */
    protected \${$columnName}_unserialized;\n";
    }

    /**
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addColumnAttributeConvertedDeclaration(string &$script, Column $column): void
    {
        $attributeName = '$' . $column->getLowercasedName() . '_converted';
        $script .= "
    /**
     * @var array<string>|null
     */
    protected ?array $attributeName = null;\n";
    }

    /**
     * Adds the constructor for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addConstructor(string &$script): void
    {
        $this->addConstructorComment($script);
        $this->addConstructorOpen($script);
        $this->addConstructorBody($script);
        $this->addConstructorClose($script);
    }

    /**
     * Adds the comment for the constructor
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addConstructorComment(string &$script): void
    {
        $script .= "
    /**
     * Initializes internal state of " . $this->getQualifiedClassName() . ' object.';
        if ($this->hasDefaultValues()) {
            $script .= "
     *
     * @see static::applyDefaultValues()";
        }
        $script .= "
     */";
    }

    /**
     * Adds the function declaration for the constructor.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addConstructorOpen(string &$script): void
    {
        $script .= "
    public function __construct()
    {";
    }

    /**
     * Adds the function body for the constructor.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addConstructorBody(string &$script): void
    {
        if ($this->getParentClass() !== null) {
            $script .= "
        parent::__construct();";
        }
        if ($this->hasDefaultValues()) {
            $script .= "
        \$this->applyDefaultValues();";
        }
    }

    /**
     * Adds the function close for the constructor.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addConstructorClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds the base object functions.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addBaseObjectMethods(string &$script): void
    {
        $this->declareClasses(
            'ReflectionClass',
            'ReflectionProperty',
        );
        $script .= $this->renderTemplate('baseObjectMethods', [
            'className' => $this->getUnqualifiedClassName(),
            'hasArrayKey' => count($this->getTable()->getPrimaryKey()) > 1,
            'hasFks' => $this->getTable()->hasRelations(),
        ]);
    }

    /**
     * Adds the getter methods for the column values.
     * This is here because it is probably generic enough to apply to templates being generated
     * in different PHP versions.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addColumnAccessorMethods(string &$script): void
    {
        $table = $this->getTable();

        foreach ($table->getColumns() as $col) {
            $type = $col->getType();
            // if they're not using the DateTime class then we will generate "compatibility" accessor method
            if (
                $type === PropelTypes::DATE
                || $type === PropelTypes::DATETIME
                || $type === PropelTypes::TIME
                || $type === PropelTypes::TIMESTAMP
            ) {
                $this->addTemporalAccessor($script, $col);
            } elseif ($type === PropelTypes::OBJECT) {
                $this->addObjectAccessor($script, $col);
            } elseif ($type === PropelTypes::PHP_ARRAY) {
                $this->addArrayAccessor($script, $col);
                if ($col->isNamePlural()) {
                    $this->addHasArrayElement($script, $col);
                }
            } elseif ($type === PropelTypes::JSON) {
                $this->addJsonAccessor($script, $col);
            } elseif ($col->isEnumType()) {
                $this->addEnumAccessor($script, $col);
            } elseif ($col->isSetType()) {
                $this->addSetAccessor($script, $col);
                if ($col->isNamePlural()) {
                    $this->addHasArrayElement($script, $col);
                }
            } elseif ($col->isBooleanType()) {
                $this->addDefaultAccessor($script, $col);
                $this->addBooleanAccessor($script, $col);
            } else {
                $this->addDefaultAccessor($script, $col);
            }

            if ($col->isLazyLoad()) {
                $this->addLazyLoader($script, $col);
            }
        }
    }

    /**
     * Adds the mutator (setter) methods for setting column values.
     * This is here because it is probably generic enough to apply to templates being generated
     * in different PHP versions.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addColumnMutatorMethods(string &$script): void
    {
        foreach ($this->getTable()->getColumns() as $col) {
            if ($col->getType() === PropelTypes::OBJECT) {
                $this->addObjectMutator($script, $col);
            } elseif ($col->isLobType()) {
                $this->addLobMutator($script, $col);
            } elseif (
                $col->getType() === PropelTypes::DATE
                || $col->getType() === PropelTypes::DATETIME
                || $col->getType() === PropelTypes::TIME
                || $col->getType() === PropelTypes::TIMESTAMP
            ) {
                $this->addTemporalMutator($script, $col);
            } elseif ($col->getType() === PropelTypes::PHP_ARRAY) {
                $this->addArrayMutator($script, $col);
                if ($col->isNamePlural()) {
                    $this->addAddArrayElement($script, $col);
                    $this->addRemoveArrayElement($script, $col);
                }
            } elseif ($col->getType() === PropelTypes::JSON) {
                $this->addJsonMutator($script, $col);
            } elseif ($col->isEnumType()) {
                $this->addEnumMutator($script, $col);
            } elseif ($col->isSetType()) {
                $this->addSetMutator($script, $col);
                if ($col->isNamePlural()) {
                    $this->addAddArrayElement($script, $col);
                    $this->addRemoveArrayElement($script, $col);
                }
            } elseif ($col->isBooleanType()) {
                $this->addBooleanMutator($script, $col);
            } else {
                $this->addDefaultMutator($script, $col);
            }
        }
    }

    /**
     * Adds the base object hook functions.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addHookMethods(string &$script): void
    {
        $hooks = [];
        foreach (['pre', 'post'] as $hook) {
            foreach (['Insert', 'Update', 'Save', 'Delete'] as $action) {
                $hooks[$hook . $action] = strpos($script, 'function ' . $hook . $action . '(') === false;
            }
        }

        /** @var string|null $className */
        $className = ClassTools::classname($this->getBaseClass());
        $hooks['hasBaseClass'] = $this->getBehaviorContent('parentClass') !== null || $className !== null;

        $script .= $this->renderTemplate('baseObjectMethodHook', $hooks);
    }

    /**
     * Adds the applyDefaults() method, which is called from the constructor.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValues(string &$script): void
    {
        $this->addApplyDefaultValuesComment($script);
        $this->addApplyDefaultValuesOpen($script);
        $this->addApplyDefaultValuesBody($script);
        $this->addApplyDefaultValuesClose($script);
    }

    /**
     * Adds the comment for the applyDefaults method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesComment(string &$script): void
    {
        $script .= "
    /**
     * Applies default values to this object.
     * This method should be called from the object's constructor (or
     * equivalent initialization method).
     *
     * @see __construct()
     *
     * @return void
     */";
    }

    /**
     * Adds the function declaration for the applyDefaults method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesOpen(string &$script): void
    {
        $script .= "
    public function applyDefaultValues(): void
    {";
    }

    /**
     * Adds the function body of the applyDefault method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesBody(string &$script): void
    {
        $table = $this->getTable();
        // FIXME - Apply support for PHP default expressions here
        // see: http://propel.phpdb.org/trac/ticket/378

        $colsWithDefaults = [];
        foreach ($table->getColumns() as $column) {
            $def = $column->getDefaultValue();
            if ($def !== null && !$def->isExpression()) {
                $colsWithDefaults[] = $column;
            }
        }

        foreach ($colsWithDefaults as $column) {
            $clo = $column->getLowercasedName();
            $defaultValue = $this->getDefaultValueString($column);
            if ($column->isTemporalType()) {
                $dateTimeClass = $this->getDateTimeClass($column);
                $defaultValue = "PropelDateTime::newInstance($defaultValue, null, '$dateTimeClass')";
            }
            $script .= "
        \$this->" . $clo . " = $defaultValue;";
        }
    }

    /**
     * Adds the function close for the applyDefaults method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addApplyDefaultValuesClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds a date/time/timestamp getter method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addTemporalAccessor(string &$script, Column $column): void
    {
        $this->addTemporalAccessorComment($script, $column);
        $this->addTemporalAccessorOpen($script, $column);
        $this->addTemporalAccessorBody($script, $column);
        $this->addTemporalAccessorClose($script);
    }

    /**
     * Adds the comment for a temporal accessor.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addTemporalAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $dateTimeClass = $this->getDateTimeClass($column);

        $handleMysqlDate = false;
        $mysqlInvalidDateString = '';
        if ($this->getPlatform() instanceof MysqlPlatform) {
            if (in_array($column->getType(), [PropelTypes::TIMESTAMP, PropelTypes::DATETIME], true)) {
                $handleMysqlDate = true;
                $mysqlInvalidDateString = '0000-00-00 00:00:00';
            } elseif ($column->getType() === PropelTypes::DATE) {
                $handleMysqlDate = true;
                $mysqlInvalidDateString = '0000-00-00';
            }
            // 00:00:00 is a valid time, so no need to check for that.
        }

        $orNull = $column->isNotNull() ? '' : '|null';
        $descriptionReturnValueNull = $column->isNotNull() ? '' : ', NULL if column is NULL';
        $descriptionReturnMysqlInvalidDate = $handleMysqlDate ? ", and 0 if column value is $mysqlInvalidDateString" : '';

        $script .= "
    /**
     * Get the [optionally formatted] temporal [$clo] column value.{$this->getColumnDescriptionDoc($column)}
     *
     * @psalm-return (\$format is null ? {$dateTimeClass}{$orNull} : string{$orNull})
     *
     * @param string|null \$format The date/time format string (either date()-style or strftime()-style).
     *   If format is NULL, then the raw $dateTimeClass object will be returned.
     *
     * @return {$dateTimeClass}|string{$orNull} Formatted date/time value as string or $dateTimeClass object (if format is NULL){$descriptionReturnValueNull}{$descriptionReturnMysqlInvalidDate}.
     */";
    }

    /**
     * Gets the default format for a temporal column from the configuration
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string|null
     */
    protected function getTemporalTypeDefaultFormat(Column $column): ?string
    {
        $configKey = $this->getTemporalTypeDefaultFormatConfigKey($column);

        return $configKey ? $this->getBuildProperty($configKey) : null;
    }

    /**
     * Knows which key in the configuration holds the default format for a
     * temporal type column.
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string|null
     */
    protected function getTemporalTypeDefaultFormatConfigKey(Column $column): ?string
    {
        switch ($column->getType()) {
            case PropelTypes::DATE:
                return 'generator.dateTime.defaultDateFormat';
            case PropelTypes::TIME:
                return 'generator.dateTime.defaultTimeFormat';
            case PropelTypes::TIMESTAMP:
            case PropelTypes::DATETIME:
                return 'generator.dateTime.defaultTimeStampFormat';
            default:
                return null;
        }
    }

    /**
     * Adds the function declaration for a temporal accessor.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addTemporalAccessorOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();

        $defaultfmt = $this->getTemporalTypeDefaultFormat($column);
        $visibility = $column->getAccessorVisibility();

        $format = var_export($defaultfmt, true);
        if ($format === 'NULL') {
            $format = 'null';
        }

        $script .= "
    " . $visibility . " function get$cfc(\$format = " . $format;
        if ($column->isLazyLoad()) {
            $script .= ', $con = null';
        }
        $script .= ")
    {";
    }

    /**
     * Gets accessor lazy loaded snippets.
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    protected function getAccessorLazyLoadSnippet(Column $column): string
    {
        if ($column->isLazyLoad()) {
            $clo = $column->getLowercasedName();
            $defaultValueString = 'null';
            $def = $column->getDefaultValue();
            if ($def !== null && !$def->isExpression()) {
                $defaultValueString = $this->getDefaultValueString($column);
            }

            return "
        if (!\$this->{$clo}_isLoaded && \$this->{$clo} === {$defaultValueString} && !\$this->isNew()) {
            \$this->load{$column->getPhpName()}(\$con);
        }
";
        }

        return '';
    }

    /**
     * Adds the body of the temporal accessor.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addTemporalAccessorBody(string &$script, Column $column): void
    {
        $this->declareClass('DateTimeInterface');
        $clo = $column->getLowercasedName();

        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        if (\$format === null) {
            return \$this->$clo;
        } else {
            return \$this->$clo instanceof DateTimeInterface ? \$this->{$clo}->format(\$format) : null;
        }";
    }

    /**
     * Adds the body of the temporal accessor.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addTemporalAccessorClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds an object getter method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addObjectAccessor(string &$script, Column $column): void
    {
        $this->addDefaultAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addObjectAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Adds the function body for an object accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addObjectAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $typeHint = $column->getTypeHint();
        $docHint = !$typeHint ? '' : "
                /** @var $typeHint \$unserializedString */";

        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

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
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addJsonAccessor(string &$script, Column $column): void
    {
        $this->addJsonAccessorComment($script, $column);
        $this->addJsonAccessorOpen($script, $column);
        $this->addJsonAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for a json accessor method (a getter).
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addJsonAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc($column)}
     *
     * @param bool \$asArray Returns the JSON data as array instead of object";
        if ($column->isLazyLoad()) {
            $script .= "
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     *
     * @return object|array{$orNull}
     */";
    }

    /**
     * Adds the function declaration for a JSON accessor.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addJsonAccessorOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();

        $script .= "
    " . $visibility . " function get$cfc(\$asArray = true";
        if ($column->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }

        $script .= ")
    {";
    }

    /**
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addJsonAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $script .= "
        return json_decode(\$this->$clo, \$asArray);";
    }

    /**
     * Adds an array getter method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addArrayAccessor(string &$script, Column $column): void
    {
        $this->addDefaultAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addArrayAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Adds the function body for an array accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addArrayAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

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
     * Adds a boolean isser method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addBooleanAccessor(string &$script, Column $column): void
    {
        $name = self::getBooleanAccessorName($column);
        if (in_array($name, ClassTools::getPropelReservedMethods(), true)) {
            //TODO: Issue a warning telling the user to use default accessors
            return; // Skip boolean accessors for reserved names
        }
        $this->addDefaultAccessorComment($script, $column);
        $this->addBooleanAccessorOpen($script, $column);
        $this->addBooleanAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Returns the name to be used as boolean accessor name
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    protected static function getBooleanAccessorName(Column $column): string
    {
        $name = $column->getCamelCaseName();
        if (!preg_match('/^(?:is|has)(?=[A-Z])/', $name)) {
            $name = 'is' . ucfirst($name);
        }

        return $name;
    }

    /**
     * Adds the function declaration for a boolean accessor.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addBooleanAccessorOpen(string &$script, Column $column): void
    {
        $name = self::getBooleanAccessorName($column);
        $visibility = $column->getAccessorVisibility();

        $script .= "
    " . $visibility . " function $name(";
        if ($column->isLazyLoad()) {
            $script .= '?ConnectionInterface $con = null';
        }

        $script .= ")
    {";
    }

    /**
     * Adds the function body for a boolean accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addBooleanAccessorBody(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();

        $script .= "
        return \$this->get$cfc(";

        if ($column->isLazyLoad()) {
            $script .= '$con';
        }

        $script .= ');';
    }

    /**
     * Adds an enum getter method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addEnumAccessor(string &$script, Column $column): void
    {
        $this->addEnumAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addEnumAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for an enum accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addEnumAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc($column)}";
        if ($column->isLazyLoad()) {
            $script .= "
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
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
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addEnumAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

        $script .= "
        if (\$this->$clo === null) {
            return null;
        }
        \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($column) . ");
        if (!isset(\$valueSet[\$this->$clo])) {
            throw new PropelException('Unknown stored enum key: ' . \$this->$clo);
        }

        return \$valueSet[\$this->$clo];";
    }

    /**
     * Adds a SET column getter method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addSetAccessor(string &$script, Column $column): void
    {
        $this->addSetAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addSetAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for a SET column accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addSetAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc($column)}";
        if ($column->isLazyLoad()) {
            $script .= "
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     *
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     *
     * @return array|null
     */";
    }

    /**
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    protected function getColumnDescriptionDoc(Column $column): string
    {
        $description = $column->getDescription();
        if (!$description) {
            return '';
        }

        return "
     *
     * {$column->getDescription()}";
    }

    /**
     * Adds the function body for a SET column accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addSetAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cloConverted = $clo . '_converted';
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }
        $this->declareClasses(
            'Propel\Common\Util\SetColumnConverter',
            'Propel\Common\Exception\SetColumnConverterException',
        );

        $script .= "
        if (\$this->$cloConverted === null) {
            \$this->$cloConverted = [];
        }
        if (!\$this->$cloConverted && \$this->$clo !== null) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($column) . ");
            try {
                \$this->$cloConverted = SetColumnConverter::convertIntToArray(\$this->$clo, \$valueSet);
            } catch (SetColumnConverterException \$e) {
                throw new PropelException('Unknown stored set key: ' . \$e->getValue(), \$e->getCode(), \$e);
            }
        }

        return \$this->$cloConverted;";
    }

    /**
     * Adds a tester method for an array column.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addHasArrayElement(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();
        $singularPhpName = $column->getPhpSingularName();
        $columnType = ($column->getType() === PropelTypes::PHP_ARRAY) ? 'array' : 'set';
        $script .= "
    /**
     * Test the presence of a value in the [$clo] $columnType column value.
     *
     * @param mixed \$value";
        if ($column->isLazyLoad()) {
            $script .= "
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     *
     * @return bool
     */
    $visibility function has$singularPhpName(\$value";
        if ($column->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }

        $script .= "): bool
    {
        return in_array(\$value, \$this->get$cfc(";
        if ($column->isLazyLoad()) {
            $script .= '$con';
        }

        $script .= "));
    }
";
    }

    /**
     * Adds a normal (non-temporal) getter method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addDefaultAccessor(string &$script, Column $column): void
    {
        $this->addDefaultAccessorComment($script, $column);
        $this->addDefaultAccessorOpen($script, $column);
        $this->addDefaultAccessorBody($script, $column);
        $this->addDefaultAccessorClose($script);
    }

    /**
     * Add the comment for a default accessor method (a getter).
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addDefaultAccessorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $returnType = $column->getTypeHint() ?: $column->getPhpType() ?: 'mixed';

        $script .= "
    /**
     * Get the [$clo] column value.{$this->getColumnDescriptionDoc($column)}";
        if ($column->isLazyLoad()) {
            $script .= "
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     *
     * @return {$returnType}|null
     */";
    }

    /**
     * Adds the function declaration for a default accessor.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addDefaultAccessorOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $column->getAccessorVisibility();

        $script .= "
    " . $visibility . " function get$cfc(";
        if ($column->isLazyLoad()) {
            $script .= '?ConnectionInterface $con = null';
        }

        $script .= ")
    {";
    }

    /**
     * Adds the function body for a default accessor method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addDefaultAccessorBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        if ($column->isLazyLoad()) {
            $script .= $this->getAccessorLazyLoadSnippet($column);
        }

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
    protected function addDefaultAccessorClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds the lazy loader method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addLazyLoader(string &$script, Column $column): void
    {
        $this->addLazyLoaderComment($script, $column);
        $this->addLazyLoaderOpen($script, $column);
        $this->addLazyLoaderBody($script, $column);
        $this->addLazyLoaderClose($script);
    }

    /**
     * Adds the comment for the lazy loader method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addLazyLoaderComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $script .= "
    /**
     * Load the value for the lazy-loaded [$clo] column.
     *
     * This method performs an additional query to return the value for
     * the [$clo] column, since it is not populated by
     * the hydrate() method.
     *
     * @param \$con ConnectionInterface (optional) The ConnectionInterface connection to use.
     *
     * @throws \Propel\Runtime\Exception\PropelException - any underlying error will be wrapped and re-thrown.
     *
     * @return void
     */";
    }

    /**
     * Adds the function declaration for the lazy loader method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addLazyLoaderOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $script .= "
    protected function load$cfc(?ConnectionInterface \$con = null)
    {";
    }

    /**
     * Adds the function body for the lazy loader method.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addLazyLoaderBody(string &$script, Column $column): void
    {
        $platform = $this->getPlatform();
        $clo = $column->getLowercasedName();
        $columnConstant = $this->getColumnConstant($column);
        $queryClassName = $this->getQueryClassName();

        // pdo_sqlsrv driver requires the use of PDOStatement::bindColumn() or a hex string will be returned
        if ($column->getType() === PropelTypes::BLOB && $platform instanceof SqlsrvPlatform) {
            $script .= "
        \$c = \$this->buildPkeyCriteria();
        \$c->addSelectColumn($columnConstant);
        try {
            \$row = [0 => null];
            \$dataFetcher = {$queryClassName}::create(null, \$c)->fetch(\$con);
            if (\$dataFetcher instanceof PDODataFetcher) {
                \$dataFetcher->bindColumn(1, \$row[0], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
            }
            \$row = \$dataFetcher->fetch(PDO::FETCH_BOUND);
            \$dataFetcher->close();";
        } else {
            $script .= "
        \$c = \$this->buildPkeyCriteria();
        \$c->addSelectColumn($columnConstant);
        try {
            \$dataFetcher = {$queryClassName}::create(null, \$c)->fetch(\$con);
            \$row = \$dataFetcher->fetch();
            \$dataFetcher->close();";
        }

        $script .= "\n
            \$firstColumn = is_bool(\$row) ? null : current(\$row);\n";

        if ($column->getType() === PropelTypes::CLOB && $platform instanceof OraclePlatform) {
            // PDO_OCI returns a stream for CLOB objects, while other PDO adapters return a string...
            $script .= "
            if (\$firstColumn) {
                \$this->$clo = stream_get_contents(\$firstColumn);
            }";
        } elseif ($column->isLobType() && !$platform->hasStreamBlobImpl()) {
            $script .= "
            \$this->$clo = \$this->writeResource(\$firstColumn);";
        } elseif ($column->isPhpPrimitiveType()) {
            $script .= "
            \$this->$clo = (\$firstColumn !== null) ? (" . $column->getPhpType() . ')$firstColumn : null;';
        } elseif ($column->isPhpObjectType()) {
            $script .= "
            \$this->$clo = (\$firstColumn !== null) ? new " . $column->getPhpType() . '($firstColumn) : null;';
        } elseif ($column->getType() === PropelTypes::UUID_BINARY) {
            $uuidSwapFlag = $this->getUuidSwapFlagLiteral();
            $script .= "
            if (is_resource(\$firstColumn)) {
                \$firstColumn = stream_get_contents(\$firstColumn);
            }
            \$this->$clo = UuidConverter::binToUuid(\$firstColumn, $uuidSwapFlag);";
        } else {
            $script .= "
            \$this->$clo = \$firstColumn;";
        }

        $script .= "
            \$this->" . $clo . "_isLoaded = true;
        } catch (Exception \$e) {
            throw new PropelException('Error loading value for [$clo] column on demand.', 0, \$e);
        }";
    }

    /**
     * Adds the function close for the lazy loader.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addLazyLoaderClose(string &$script): void
    {
        $script .= "
    }\n";
    }

    /**
     * Adds the open of the mutator (setter) method for a column.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addMutatorOpen(string &$script, Column $column): void
    {
        $this->addMutatorComment($script, $column);
        $this->addMutatorOpenOpen($script, $column);
        $this->addMutatorOpenBody($script, $column);
    }

    /**
     * Adds the open of the mutator (setter) method for a JSON column.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addJsonMutatorOpen(string &$script, Column $column): void
    {
        $this->addJsonMutatorComment($script, $column);
        $this->addMutatorOpenOpen($script, $column);
        $this->addMutatorOpenBody($script, $column);
    }

    /**
     * Adds the comment for a mutator.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addJsonMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.{$this->getColumnDescriptionDoc($column)}
     *
     * @param string|array|object{$orNull} \$v new value
     *
     * @return \$this
     */";
    }

    /**
     * Adds the comment for a mutator.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        if ($column->getTypeHint()) {
            $type = $column->getTypeHint();
        } elseif ($column->getType() === PropelTypes::OBJECT) {
            $type = 'mixed';
        } elseif ($column->isLobType()) {
            $phpType = $column->getPhpType();
            $type = $phpType && $phpType !== 'string' ? "$phpType|string" : 'string';
        } elseif ($column->getPhpType()) {
            $type = $column->getPhpType();
        } else {
            $type = 'mixed';
        }

        if (!$column->isNotNull()) {
            $type .= '|null';
        }

        $script .= "
    /**
     * Set the value of [$clo] column.{$this->getColumnDescriptionDoc($column)}
     *
     * @param $type \$v New value";
        if ($column->getType() === PropelTypes::OBJECT) {
            $script .= "
     *
     * @throws \RuntimeException";
        }
        $script .= "
     *
     * @return \$this
     */";
    }

    /**
     * Adds the mutator function declaration.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addMutatorOpenOpen(string &$script, Column $column): void
    {
        $cfc = $column->getPhpName();
        $visibility = $this->getTable()->isReadOnly() ? 'protected' : $column->getMutatorVisibility();

        $typeHint = '';
        $null = '';

        if ($column->getTypeHint()) {
            $typeHint = $column->getTypeHint();
            if ($typeHint !== 'array') {
                $typeHint = $this->declareClass($typeHint);
            }

            $typeHint .= ' ';

            if (!$column->isNotNull()) {
                $typeHint = '?' . $typeHint;
                $null = ' = null';
            }
        }

        $script .= "
    " . $visibility . " function set$cfc($typeHint\$v$null)
    {";
    }

    /**
     * Adds the mutator open body part.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addMutatorOpenBody(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();
        $cfc = $column->getPhpName();
        if ($column->isLazyLoad()) {
            $script .= "
        // explicitly set the is-loaded flag to true for this lazy load col;
        // it doesn't matter if the value is actually set or not (logic below) as
        // any attempt to set the value means that no db lookup should be performed
        // when the get$cfc() method is called.
        \$this->" . $clo . "_isLoaded = true;
";
        }
    }

    /**
     * Adds the close of the mutator (setter) method for a column.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addMutatorClose(string &$script, Column $column): void
    {
        $this->addMutatorCloseBody($script, $column);
        $this->addMutatorCloseClose($script, $column);
    }

    /**
     * Adds the body of the close part of a mutator.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    protected function addMutatorCloseBody(string &$script, Column $column): void
    {
        $table = $this->getTable();

        if ($column->isForeignKey()) {
            foreach ($column->getForeignKeys() as $fk) {
                $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($column->getName()));

                if (!$colFK) {
                    continue;
                }
                $attributeName = $this->getFKVarName($fk);

                $script .= "
        if (\$this->$attributeName !== null && \$this->" . $attributeName . '->get' . $colFK->getPhpName() . "() !== \$v) {
            \$this->$attributeName = null;
        }
";
            }
        }

        foreach ($column->getReferrers() as $refFK) {
            $tblFK = $this->getDatabase()->getTable($refFK->getForeignTableName());

            if ($tblFK->getName() != $table->getName()) {
                foreach ($column->getForeignKeys() as $fk) {
                    $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                    $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($column->getName()));

                    if ($refFK->isLocalPrimaryKey()) {
                        $varName = $this->getPKRefFKVarName($refFK);
                        $script .= "
        // update associated " . $tblFK->getPhpName() . "
        if (\$this->$varName !== null) {
            \$this->{$varName}->set" . $colFK->getPhpName() . "(\$v);
        }
";
                    } else {
                        $collName = $this->getRefFKCollVarName($refFK);
                        $script .= "

        // update associated " . $tblFK->getPhpName() . "
        if (\$this->$collName !== null) {
            foreach (\$this->$collName as \$referrerObject) {
                    \$referrerObject->set" . $colFK->getPhpName() . "(\$v);
                }
            }
";
                    }
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
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addMutatorCloseClose(string &$script, Column $col): void
    {
        $script .= "
        return \$this;
    }
";
    }

    /**
     * Adds a setter for BLOB columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addLobMutator(string &$script, Column $col): void
    {
        $this->addMutatorOpen($script, $col);
        $clo = $col->getLowercasedName();
        $columnConstant = $this->getColumnConstant($col);

        $script .= "
        // Because BLOB columns are streams in PDO we have to assume that they are
        // always modified when a new value is passed in.  For example, the contents
        // of the stream itself may have changed externally.
        \$this->$clo = \$this->writeResource(\$v);

        \$this->modifiedColumns[$columnConstant] = true;
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a setter method for date/time/timestamp columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addTemporalMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $dateTimeClass = $this->getDateTimeClass($col);
        $this->declareClasses('\Propel\Runtime\Util\PropelDateTime');

        $this->addTemporalMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);

        $fmt = var_export($this->getTemporalFormatter($col), true);

        $script .= "
        \$dt = PropelDateTime::newInstance(\$v, null, '$dateTimeClass');
        if (\$this->$clo !== null || \$dt !== null) {";

        $def = $col->getDefaultValue();
        if ($def !== null && !$def->isExpression()) {
            $defaultValue = $this->getDefaultValueString($col);
            $script .= "
            if (
                \$dt !== \$this->{$clo} // normalized values don't match
                || \$dt->format($fmt) === $defaultValue // or the entered value matches the default
            ) {";
        } else {
            switch ($col->getType()) {
                case 'DATE':
                    $format = 'Y-m-d';

                    break;
                case 'TIME':
                    $format = 'H:i:s.u';

                    break;
                default:
                    $format = 'Y-m-d H:i:s.u';
            }
            $script .= "
            if (\$this->{$clo} === null || \$dt === null || \$dt->format('$format') !== \$this->{$clo}->format('$format')) {";
        }

        $script .= "
                \$this->$clo = \$dt === null ? null : clone \$dt;
                \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
            }
        } // if either are not null
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * @param string $script
     * @param \Propel\Generator\Model\Column $col
     *
     * @return void
     */
    public function addTemporalMutatorComment(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $orNull = $col->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Sets the value of [$clo] column to a normalized version of the date/time value specified.{$this->getColumnDescriptionDoc($col)}
     *
     * @param \DateTimeInterface|string|int{$orNull} \$v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     *
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds a setter for Object columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addObjectMutator(string &$script, Column $col): void
    {
        $this->declareClass('\RuntimeException');

        $clo = $col->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $this->addMutatorOpen($script, $col);
        $columnConstant = $this->getColumnConstant($col);

        $script .= "
        if (\$this->$clo === null || stream_get_contents(\$this->$clo) !== serialize(\$v)) {
            \$this->$cloUnserialized = \$v;
            \$this->$clo = fopen('php://memory', 'r+');
            if (\$this->$clo === false) {
                throw new RuntimeException('Could not open memory stream');
            }
            fwrite(\$this->$clo, serialize(\$v));
            \$this->modifiedColumns[$columnConstant] = true;
        }
        rewind(\$this->$clo);
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a setter for Json columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addJsonMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $this->addJsonMutatorOpen($script, $col);

        $script .= "
        if (is_string(\$v)) {
            // JSON as string needs to be decoded/encoded to get a reliable comparison (spaces, ...)
            \$v = json_decode(\$v);
        }
        \$encodedValue = json_encode(\$v);
        if (\$encodedValue !== \$this->$clo) {
            \$this->$clo = \$encodedValue;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a setter for Array columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addArrayMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cloUnserialized = $clo . '_unserialized';
        $this->addMutatorOpen($script, $col);

        $script .= "
        if (\$this->$cloUnserialized !== \$v) {
            \$this->$cloUnserialized = \$v;
            \$this->$clo = '| ' . implode(' | ', \$v) . ' |';
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds a push method for an array column.
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addAddArrayElement(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cfc = $col->getPhpName();
        $visibility = $col->getAccessorVisibility();
        $singularPhpName = $col->getPhpSingularName();
        $columnType = ($col->getType() === PropelTypes::PHP_ARRAY) ? 'array' : 'set';
        $script .= "
    /**
     * Adds a value to the [$clo] $columnType column value.{$this->getColumnDescriptionDoc($col)}
     *
     * @param mixed \$value";

        if ($col->isLazyLoad()) {
            $script .= "
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }

        $script .= "
     *
     * @return \$this The current object (for fluent API support)
     */
    $visibility function add$singularPhpName(\$value";
        if ($col->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }

        $script .= ")
    {
        \$currentArray = \$this->get$cfc(";
        if ($col->isLazyLoad()) {
            $script .= '$con';
        }

        $script .= ");
        \$currentArray[] = \$value;
        \$this->set$cfc(\$currentArray);

        return \$this;
    }
";
    }

    /**
     * Adds a remove method for an array column.
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addRemoveArrayElement(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $cfc = $col->getPhpName();
        $visibility = $col->getAccessorVisibility();
        $singularPhpName = $col->getPhpSingularName();
        $columnType = ($col->getType() === PropelTypes::PHP_ARRAY) ? 'array' : 'set';
        $script .= "
    /**
     * Removes a value from the [$clo] $columnType column value.{$this->getColumnDescriptionDoc($col)}
     *
     * @param mixed \$value";

        if ($col->isLazyLoad()) {
            $script .= "
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con An optional ConnectionInterface connection to use for fetching this lazy-loaded column.";
        }
        $script .= "
     *
     * @return \$this The current object (for fluent API support)
     */
    $visibility function remove$singularPhpName(\$value";
        if ($col->isLazyLoad()) {
            $script .= ', ?ConnectionInterface $con = null';
        }
        // we want to reindex the array, so array_ functions are not the best choice
        $script .= ")
    {
        \$targetArray = [];
        foreach (\$this->get$cfc(";
        if ($col->isLazyLoad()) {
            $script .= '$con';
        }
        $script .= ") as \$element) {
            if (\$element != \$value) {
                \$targetArray[] = \$element;
            }
        }
        \$this->set$cfc(\$targetArray);

        return \$this;
    }
";
    }

    /**
     * Adds a setter for Enum columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addEnumMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $this->addEnumMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);

        $script .= "
        if (\$v !== null) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
            \$keyId = array_search(\$v, \$valueSet);
            if (!is_int(\$keyId)) {
                throw new PropelException(sprintf('Value \"%s\" is not accepted in this enumerated column', \$v));
            }
            \$v = \$keyId;
        }

        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds the comment for an enum mutator.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addEnumMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.{$this->getColumnDescriptionDoc($column)}
     *
     * @param string{$orNull} \$v new value
     *
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     *
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds a setter for SET column mutator.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addSetMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();
        $this->addSetMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);
        $cloConverted = $clo . '_converted';

        $this->declareClasses(
            'Propel\Common\Util\SetColumnConverter',
            'Propel\Common\Exception\SetColumnConverterException',
        );

        $script .= "
        if (\$this->$cloConverted === null || count(array_diff(\$this->$cloConverted, \$v)) > 0 || count(array_diff(\$v, \$this->$cloConverted)) > 0) {
            \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
            try {
                \$v = SetColumnConverter::convertToInt(\$v, \$valueSet);
            } catch (SetColumnConverterException \$e) {
                throw new PropelException(sprintf('Value \"%s\" is not accepted in this set column', \$e->getValue()), \$e->getCode(), \$e);
            }
            if (\$this->$clo !== \$v) {
                \$this->$cloConverted = null;
                \$this->$clo = \$v;
                \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
            }
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds the comment for a SET column mutator.
     *
     * @param string $script
     * @param \Propel\Generator\Model\Column $column
     *
     * @return void
     */
    public function addSetMutatorComment(string &$script, Column $column): void
    {
        $clo = $column->getLowercasedName();

        $orNull = $column->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Set the value of [$clo] column.{$this->getColumnDescriptionDoc($column)}
     *
     * @param array{$orNull} \$v new value
     *
     * @throws \\Propel\\Runtime\\Exception\\PropelException
     *
     * @return \$this The current object (for fluent API support)
     */";
    }

    /**
     * Adds setter method for boolean columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addBooleanMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $this->addBooleanMutatorComment($script, $col);
        $this->addMutatorOpenOpen($script, $col);
        $this->addMutatorOpenBody($script, $col);

        $script .= "
        if (\$v !== null) {
            \$v = is_string(\$v)
                ? !in_array(strtolower(\$v), ['false', 'off', '-', 'no', 'n', '0', ''])
                : (bool)\$v;
        }

        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * @param string $script
     * @param \Propel\Generator\Model\Column $col
     *
     * @return void
     */
    public function addBooleanMutatorComment(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $orNull = $col->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Sets the value of the [$clo] column.
     *
     * Non-boolean arguments are converted using the following rules:
     * - 1, '1', 'true', 'on', 'yes' are converted to boolean true
     * - 0, '0', 'false', 'off', 'no' are converted to boolean false
     * Check on string values is case insensitive (so 'FaLsE' is seen as 'false').{$this->getColumnDescriptionDoc($col)}
     *
     * @param string|int|bool{$orNull} \$v The new value
     *
     * @return \$this
     */";
    }

    /**
     * Adds setter method for "normal" columns.
     *
     * @see parent::addColumnMutators()
     *
     * @param string $script The script will be modified in this method.
     * @param \Propel\Generator\Model\Column $col The current column.
     *
     * @return void
     */
    protected function addDefaultMutator(string &$script, Column $col): void
    {
        $clo = $col->getLowercasedName();

        $this->addMutatorOpen($script, $col);

        // Perform type-casting to ensure that we can use type-sensitive
        // checking in mutators.
        if ($col->isPhpPrimitiveType()) {
            $script .= "
        if (\$v !== null) {
            \$v = (" . $col->getPhpType() . ")\$v;
        }
";
        }

        $script .= "
        if (\$this->$clo !== \$v) {
            \$this->$clo = \$v;
            \$this->modifiedColumns[" . $this->getColumnConstant($col) . "] = true;
        }
";
        $this->addMutatorClose($script, $col);
    }

    /**
     * Adds the hasOnlyDefaultValues() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValues(string &$script): void
    {
        $this->addHasOnlyDefaultValuesComment($script);
        $this->addHasOnlyDefaultValuesOpen($script);
        $this->addHasOnlyDefaultValuesBody($script);
        $this->addHasOnlyDefaultValuesClose($script);
    }

    /**
     * Adds the comment for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesComment(string &$script): void
    {
        $script .= "
    /**
     * Indicates whether the columns in this object are only set to default values.
     *
     * This method can be used in conjunction with isModified() to indicate whether an object is both
     * modified _and_ has some values set which are non-default.
     *
     * @return bool Whether the columns in this object are only been set with default values.
     */";
    }

    /**
     * Adds the function declaration for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesOpen(string &$script): void
    {
        $script .= "
    public function hasOnlyDefaultValues(): bool
    {";
    }

    /**
     * Adds the function body for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesBody(string &$script): void
    {
        $table = $this->getTable();
        $colsWithDefaults = [];
        foreach ($table->getColumns() as $col) {
            $def = $col->getDefaultValue();
            if ($def !== null && !$def->isExpression()) {
                $colsWithDefaults[] = $col;
            }
        }

        foreach ($colsWithDefaults as $col) {
            /** @var \Propel\Generator\Model\Column $col */
            $clo = $col->getLowercasedName();
            $accessor = "\$this->$clo";
            if ($col->isTemporalType()) {
                $fmt = $this->getTemporalFormatter($col);
                $accessor = "\$this->$clo && \$this->{$clo}->format('$fmt')";
            }
            $notEquals = '!==';
            $defaultValueString = $this->getDefaultValueString($col);
            if (strpos($defaultValueString, 'new ') === 0) {
                $notEquals = '!='; // allow object-comparison for custom PHP types
            }
            $script .= "
        if ($accessor $notEquals $defaultValueString) {
            return false;
        }\n";
        }
    }

    /**
     * Adds the function close for the hasOnlyDefaultValues method
     *
     * @see addHasOnlyDefaultValues
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHasOnlyDefaultValuesClose(string &$script): void
    {
        $script .= "
        // otherwise, everything was equal, so return TRUE
        return true;";
        $script .= "
    }
";
    }

    /**
     * Adds the hydrate() method, which sets attributes of the object based on a ResultSet.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrate(string &$script): void
    {
        $this->addHydrateComment($script);
        $this->addHydrateOpen($script);
        $this->addHydrateBody($script);
        $this->addHydrateClose($script);
    }

    /**
     * Adds the comment for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateComment(string &$script): void
    {
        $script .= "
    /**
     * Hydrates (populates) the object variables with values from the database resultset.
     *
     * An offset (0-based \"start column\") is specified so that objects can be hydrated
     * with a subset of the columns in the resultset rows. This is needed, for example,
     * for results of JOIN queries where the resultset row includes columns from two or
     * more tables.
     *
     * @param array \$row The row returned by DataFetcher->fetch().
     * @param int \$startcol 0-based offset column which indicates which resultset column to start with.
     * @param bool \$rehydrate Whether this object is being re-hydrated from the database.
     * @param string \$indexType The index type of \$row. Mostly DataFetcher->getIndexType().
                                  One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                            TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @throws \Propel\Runtime\Exception\PropelException - Any caught Exception will be rewrapped as a PropelException.
     *
     * @return int next starting column
     */";
    }

    /**
     * Adds the function declaration for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateOpen(string &$script): void
    {
        $script .= "
    public function hydrate(array \$row, int \$startcol = 0, bool \$rehydrate = false, string \$indexType = TableMap::TYPE_NUM): int
    {";
    }

    /**
     * Adds the function body for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateBody(string &$script): void
    {
        $table = $this->getTable();
        $platform = $this->getPlatform();

        $tableMap = $this->getTableMapClassName();

        $script .= "
        try {";
        $n = 0;
        foreach ($table->getColumns() as $col) {
            if (!$col->isLazyLoad()) {
                $indexName = "TableMap::TYPE_NUM == \$indexType ? $n + \$startcol : $tableMap::translateFieldName('{$col->getPhpName()}', TableMap::TYPE_PHPNAME, \$indexType)";

                $script .= "
            \$col = \$row[$indexName];";
                $clo = $col->getLowercasedName();
                if ($col->getType() === PropelTypes::CLOB_EMU && $this->getPlatform() instanceof OraclePlatform) {
                    // PDO_OCI returns a stream for CLOB objects, while other PDO adapters return a string...
                    $script .= "
            \$this->$clo = stream_get_contents(\$col);";
                } elseif ($col->isLobType() && !$platform->hasStreamBlobImpl()) {
                    $script .= "
            \$this->$clo = \$this->writeResource(\$col);";
                } elseif ($col->isTemporalType()) {
                    $dateTimeClass = $this->getDateTimeClass($col);
                    $handleMysqlDate = false;
                    if ($this->getPlatform() instanceof MysqlPlatform) {
                        if (in_array($col->getType(), [PropelTypes::TIMESTAMP, PropelTypes::DATETIME], true)) {
                            $handleMysqlDate = true;
                            $mysqlInvalidDateString = '0000-00-00 00:00:00';
                        } elseif ($col->getType() === PropelTypes::DATE) {
                            $handleMysqlDate = true;
                            $mysqlInvalidDateString = '0000-00-00';
                        }
                        // 00:00:00 is a valid time, so no need to check for that.
                    }
                    if ($handleMysqlDate) {
                        $script .= "
            if (\$col === '$mysqlInvalidDateString') {
                \$col = null;
            }";
                    }
                    $script .= "
            \$this->$clo = (\$col !== null) ? PropelDateTime::newInstance(\$col, null, '$dateTimeClass') : null;";
                } elseif ($col->isUuidBinaryType()) {
                    $uuidSwapFlag = $this->getUuidSwapFlagLiteral();
                    $script .= "
            if (is_resource(\$col)) {
                \$col = stream_get_contents(\$col);
            }
            \$this->$clo = UuidConverter::binToUuid(\$col, $uuidSwapFlag);";
                } elseif ($col->isPhpPrimitiveType()) {
                    $script .= "
            \$this->$clo = \$col !== null ? ({$col->getPhpType()})\$col : null;";
                } elseif ($col->getType() === PropelTypes::OBJECT) {
                    $script .= "
            \$this->$clo = \$col;";
                } elseif ($col->getType() === PropelTypes::PHP_ARRAY) {
                    $cloUnserialized = $clo . '_unserialized';
                    $script .= "
            \$this->$clo = \$col;
            \$this->$cloUnserialized = null;";
                } elseif ($col->isSetType()) {
                    $cloConverted = $clo . '_converted';
                    $script .= "
            \$this->$clo = \$col;
            \$this->$cloConverted = null;";
                } elseif ($col->isPhpObjectType()) {
                    $script .= "
            \$this->$clo = (\$col !== null) ? new " . $col->getPhpType() . '($col) : null;';
                } else {
                    $script .= "
            \$this->$clo = \$col;";
                }
                $n++;
            }
        }

        if ($this->getBuildProperty('generator.objectModel.addSaveMethod')) {
            $script .= "

            \$this->resetModified();";
        }

        $script .= "
            \$this->setNew(false);

            if (\$rehydrate) {
                \$this->ensureConsistency();
            }\n";

        $this->applyBehaviorModifier('postHydrate', $script, '            ');

        $script .= "
            return \$startcol + $n; // $n = " . $this->getTableMapClass() . "::NUM_HYDRATE_COLUMNS.
        } catch (Exception \$e) {
            throw new PropelException(sprintf('Error populating %s object', " . var_export($this->getStubObjectBuilder()->getClassName(), true) . "), 0, \$e);
        }";
    }

    /**
     * Adds the function close for the hydrate method
     *
     * @see addHydrate()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addHydrateClose(string &$script): void
    {
        $script .= "
    }\n";
    }

    /**
     * Adds the buildPkeyCriteria method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteria(string &$script): void
    {
        $this->declareClass('Propel\\Runtime\\Exception\\LogicException');

        $this->addBuildPkeyCriteriaComment($script);
        $this->addBuildPkeyCriteriaOpen($script);
        $this->addBuildPkeyCriteriaBody($script);
        $this->addBuildPkeyCriteriaClose($script);
    }

    /**
     * Adds the comment for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaComment(string &$script): void
    {
        $throwsDeclaration = $this->getTable()->getPrimaryKey() ? '' : "
     *
     * @throws LogicException if no primary key is defined";
        $script .= "
    /**
     * Builds a Criteria object containing the primary key for this object.
     *
     * Unlike buildCriteria() this method includes the primary key values regardless
     * of whether they have been modified.{$throwsDeclaration}
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria The Criteria object containing value(s) for primary key(s).
     */";
    }

    /**
     * Adds the function declaration for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaOpen(string &$script): void
    {
        $script .= "
    public function buildPkeyCriteria(): Criteria
    {";
    }

    /**
     * Adds the function body for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaBody(string &$script): void
    {
        if (!$this->getTable()->getPrimaryKey()) {
            $script .= "
        throw new LogicException('The {$this->getObjectName()} object has no primary key');";

            return;
        }

        $script .= "
        \$tableMap = {$this->getTableMapClass()}::getTableMap();
        \$criteria = " . $this->getQueryClassName() . '::create();';
        foreach ($this->getTable()->getPrimaryKey() as $column) {
            $dataAccessExpression = '$this->' . $column->getLowercasedName();
            if ($column->getType() === PropelTypes::UUID_BINARY) {
                $uuidSwapFlag = $this->getUuidSwapFlagLiteral();
                $dataAccessExpression = "UuidConverter::uuidToBin($dataAccessExpression, $uuidSwapFlag)";
            }
            $columnName = $column->getName();
            $script .= "
        \${$columnName}Column = new LocalColumnExpression(\$criteria, \$tableMap->getName(), \$tableMap->getColumn('$columnName'));
        \$criteria->addFilter(\${$columnName}Column, $dataAccessExpression);";
        }
    }

    /**
     * Adds the function close for the buildPkeyCriteria method
     *
     * @see addBuildPkeyCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildPkeyCriteriaClose(string &$script): void
    {
        $script .= "

        return \$criteria;
    }
";
    }

    /**
     * Adds the buildCriteria method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteria(string &$script): void
    {
        $this->addBuildCriteriaComment($script);
        $this->addBuildCriteriaOpen($script);
        $this->addBuildCriteriaBody($script);
        $this->addBuildCriteriaClose($script);
    }

    /**
     * Adds comment for the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaComment(string &$script): void
    {
        $script .= "
    /**
     * Build a Criteria object containing the values of all modified columns in this object.
     *
     * @return \Propel\Runtime\ActiveQuery\Criteria The Criteria object containing all modified values.
     */";
    }

    /**
     * Adds the function declaration of the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaOpen(string &$script): void
    {
        $script .= "
    public function buildCriteria(): Criteria
    {";
    }

    /**
     * Adds the function body of the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaBody(string &$script): void
    {
        $script .= "
        \$tableMap = {$this->getTableMapClass()}::getTableMap();
        \$criteria = new Criteria(" . $this->getTableMapClass() . "::DATABASE_NAME);
";
        foreach ($this->getTable()->getColumns() as $col) {
            $accessValueStatement = $this->getAccessValueStatement($col);
            $columnConstant = $this->getColumnConstant($col);
            $columnName = $col->getName();
            $script .= "
        if (\$this->isColumnModified($columnConstant)) {
            \$criteria->setUpdateValue(\$tableMap->getColumn('$columnName'), $accessValueStatement);
        }";
        }
    }

    /**
     * Adds the function close of the buildCriteria method
     *
     * @see addBuildCriteria()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addBuildCriteriaClose(string &$script): void
    {
        $script .= "

        return \$criteria;
    }
";
    }

    /**
     * Adds the toArray method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addToArray(string &$script): void
    {
        $fks = $this->getTable()->getForeignKeys();
        $referrers = $this->getTable()->getReferrers();
        $hasFks = $this->getTable()->hasRelations();
        $objectClassName = $this->getUnqualifiedClassName();
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
    /**
     * Exports the object as an array.
     *
     * You can specify the key type of the array by passing one of the class
     * type constants.
     *
     * @param string \$keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     *                    TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                    Defaults to TableMap::$defaultKeyType.
     * @param bool \$includeLazyLoadColumns (optional) Whether to include lazy loaded columns. Defaults to TRUE.
     * @param array<string, array<string|bool>> \$alreadyDumpedObjects List of objects to skip to avoid recursion";
        if ($hasFks) {
            $script .= "
     * @param bool \$includeForeignObjects (optional) Whether to include hydrated related objects. Default to FALSE.";
        }
        $script .= "
     *
     * @return array<mixed> An associative array containing the field names (as keys) and field values
     */
    public function toArray(
        string \$keyType = TableMap::$defaultKeyType,
        bool \$includeLazyLoadColumns = true,
        array \$alreadyDumpedObjects = []" . ($hasFks ? ',
        bool $includeForeignObjects = false' : '') . "
    ): array {
        if (isset(\$alreadyDumpedObjects['$objectClassName'][\$this->hashCode()])) {
            return ['*RECURSION*'];
        }
        \$alreadyDumpedObjects['$objectClassName'][\$this->hashCode()] = true;
        \$keys = " . $this->getTableMapClassName() . "::getFieldNames(\$keyType);
        \$result = [";
        foreach ($this->getTable()->getColumns() as $num => $col) {
            $columnName = $col->getPhpName();
            if ($col->isLazyLoad()) {
                $script .= "
            \$keys[$num] => (\$includeLazyLoadColumns) ? \$this->get{$columnName}() : null,";
            } else {
                $script .= "
            \$keys[$num] => \$this->get{$columnName}(),";
            }
        }
        $script .= "
        ];";

        foreach ($this->getTable()->getColumns() as $num => $col) {
            if ($col->isTemporalType()) {
                $this->declareClass('DateTimeInterface');
                $script .= "
        if (\$result[\$keys[$num]] instanceof DateTimeInterface) {
            \$result[\$keys[$num]] = \$result[\$keys[$num]]->format('" . $this->getTemporalFormatter($col) . "');
        }\n";
            }
        }
        $script .= "
        \$virtualColumns = \$this->virtualColumns;
        foreach (\$virtualColumns as \$key => \$virtualColumn) {
            \$result[\$key] = \$virtualColumn;
        }\n";

        if ($hasFks) {
            $script .= "
        if (\$includeForeignObjects) {";
            foreach ($fks as $fk) {
                $attributeName = $this->getFKVarName($fk);
                $script .= "
            if (\$this->{$attributeName} !== null) {
                \$key = {$this->addToArrayKeyLookUp($fk->getPhpName(), $fk->getForeignTable(), false, '                ')}
                \$result[\$key] = \$this->{$attributeName}->toArray(\$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects, true);
            }";
            }
            foreach ($referrers as $fk) {
                if ($fk->isLocalPrimaryKey()) {
                    $script .= "
            if (\$this->" . $this->getPKRefFKVarName($fk) . " !== null) {
                \$key = {$this->addToArrayKeyLookUp($fk->getRefPhpName(), $fk->getTable(), false, '                ')}
                \$result[\$key] = \$this->" . $this->getPKRefFKVarName($fk) . "->toArray(\$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects, true);
            }";
                } else {
                    $script .= "
            if (\$this->" . $this->getRefFKCollVarName($fk) . " !== null) {
                \$key = {$this->addToArrayKeyLookUp($fk->getRefPhpName(), $fk->getTable(), true, '                ')}
                \$result[\$key] = \$this->" . $this->getRefFKCollVarName($fk) . "->toArray(null, false, \$keyType, \$includeLazyLoadColumns, \$alreadyDumpedObjects);
            }";
                }
            }
            $script .= "
        }\n";
        }
        $script .= "
        return \$result;
    }
";
    }

    // addToArray()


    /**
     * Adds the switch-statement for looking up the array-key name for toArray
     *
     * @see toArray
     *
     * @param string|null $phpName
     * @param \Propel\Generator\Model\Table $table
     * @param bool $plural
     * @param string $indent
     *
     * @return string
     */
    public function addToArrayKeyLookUp(?string $phpName, Table $table, bool $plural, string $indent): string
    {
        if (!$phpName) {
            $phpName = $table->getPhpName();
        }

        $camelCaseName = $table->getCamelCaseName();
        $fieldName = $table->getName();

        if ($plural) {
            $phpName = $this->nameProducer->toPluralName($phpName);
            $camelCaseName = $this->nameProducer->toPluralName($camelCaseName);
            $fieldName = $this->nameProducer->toPluralName($fieldName);
        }

        return "match (\$keyType) {
$indent     TableMap::TYPE_CAMELNAME => '{$camelCaseName}',
$indent     TableMap::TYPE_FIELDNAME => '{$fieldName}',
$indent     default => '{$phpName}',
$indent};";
    }

    /**
     * Adds the getByName method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByName(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $tableMapClassName = $this->getTableMapClassName();
        $script .= "
    /**
     * Retrieves a field from the object by name passed in as a string.
     *
     * @param string \$name name
     * @param string \$type The type of fieldname the \$name is of:
     *                     one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                     TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                     Defaults to TableMap::$defaultKeyType.
     *
     * @return mixed Value of field.
     */
    public function getByName(string \$name, string \$type = TableMap::$defaultKeyType)
    {
        /** @var int \$pos */
        \$pos = {$tableMapClassName}::translateFieldName(\$name, \$type, TableMap::TYPE_NUM);

        return \$this->getByPosition(\$pos);
    }
";
    }

    /**
     * Adds the getByPosition method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetByPosition(string &$script): void
    {
        $table = $this->getTable();
        $columnNames = array_values(array_map(fn ($col) => $col->getPhpName(), $table->getColumns()));
        $script .= "
    /**
     * Retrieves a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param int \$pos Position in XML schema
     *
     * @return mixed Value of field at \$pos
     */
    public function getByPosition(int \$pos)
    {
        return match (\$pos) {";
        foreach ($columnNames as $i => $columnName) {
            $script .= "
            $i => \$this->get$columnName(),";
        }
        $script .= "
            default => null
        };
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addSetByName(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $tableMapClassName = $this->getTableMapClassName();
        $script .= "
    /**
     * Sets a field from the object by name passed in as a string.
     *
     * @param string \$name
     * @param mixed \$value field value
     * @param string \$type The type of fieldname the \$name is of:
     *                one of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME
     *                TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *                Defaults to TableMap::$defaultKeyType.
     *
     * @return \$this
     */
    public function setByName(string \$name, \$value, string \$type = TableMap::$defaultKeyType)
    {
        /** @var int \$pos */
        \$pos = {$tableMapClassName}::translateFieldName(\$name, \$type, TableMap::TYPE_NUM);

        \$this->setByPosition(\$pos, \$value);

        return \$this;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addSetByPosition(string &$script): void
    {
        $table = $this->getTable();
        $script .= "
    /**
     * Sets a field from the object by Position as specified in the xml schema.
     * Zero-based.
     *
     * @param int \$pos position in xml schema
     * @param mixed \$value field value";

        if (array_any($table->getColumns(), fn (Column $col) => $col->isSetType())) {
            $script .= "
     *
     * @throws \Propel\Runtime\Exception\PropelException";
        }

        $script .= "
     *
     * @return \$this
     */
    public function setByPosition(int \$pos, \$value)
    {
        switch (\$pos) {";
        $i = 0;
        foreach ($table->getColumns() as $col) {
            $cfc = $col->getPhpName();

            $script .= "
            case $i:";

            if ($col->getType() === PropelTypes::ENUM) {
                $script .= "
                \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
                if (isset(\$valueSet[\$value])) {
                    \$value = \$valueSet[\$value];
                }";
            } elseif ($col->isSetType()) {
                $this->declareClasses(
                    'Propel\Common\Util\SetColumnConverter',
                    'Propel\Common\Exception\SetColumnConverterException',
                );
                $script .= "
                \$valueSet = " . $this->getTableMapClassName() . '::getValueSet(' . $this->getColumnConstant($col) . ");
                try {
                    \$value = SetColumnConverter::convertIntToArray(\$value, \$valueSet);
                } catch (SetColumnConverterException \$e) {
                    throw new PropelException('Unknown stored set key: ' . \$e->getValue(), \$e->getCode(), \$e);
                }
                ";
            } elseif ($col->getType() === PropelTypes::PHP_ARRAY) {
                $script .= "
                if (!is_array(\$value)) {
                    \$v = trim(substr(\$value, 2, -2));
                    \$value = \$v ? explode(' | ', \$v) : [];
                }";
            }

            $script .= "
                \$this->set$cfc(\$value);

                break;";
            $i++;
        } /* foreach */
        $script .= "
        } // switch()

        return \$this;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addFromArray(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $table = $this->getTable();
        $script .= "
    /**
     * Populates the object using an array.
     *
     * This is particularly useful when populating an object from one of the
     * request arrays (e.g. \$_POST). This method goes through the column
     * names, checking to see whether a matching key exists in populated
     * array. If so the setByName() method is called for that column.
     *
     * You can specify the key type of the array by additionally passing one
     * of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     * TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     * The default key type is the column's TableMap::$defaultKeyType.
     *
     * @param array<mixed> \$arr An array to populate the object from.
     * @param string \$keyType The type of keys the array uses.
     *
     * @return \$this
     */
    public function fromArray(array \$arr, string \$keyType = TableMap::$defaultKeyType)
    {
        \$keys = " . $this->getTableMapClassName() . "::getFieldNames(\$keyType);
";
        foreach ($table->getColumns() as $num => $col) {
            $cfc = $col->getPhpName();
            $script .= "
        if (array_key_exists(\$keys[$num], \$arr)) {
            \$this->set$cfc(\$arr[\$keys[$num]]);
        }";
        } /* foreach */
        $script .= "

        return \$this;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addImportFrom(string &$script): void
    {
        $defaultKeyType = $this->getDefaultKeyType();
        $script .= "
    /**
     * Populate the current object from a string, using a given parser format
     * <code>
     * \$book = new Book();
     * \$book->importFrom('JSON', '{\"Id\":9012,\"Title\":\"Don Juan\",\"ISBN\":\"0140422161\",\"Price\":12.99,\"PublisherId\":1234,\"AuthorId\":5678}');
     * </code>
     *
     * You can specify the key type of the array by additionally passing one
     * of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME,
     * TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     * The default key type is the column's TableMap::$defaultKeyType.
     *
     * @param mixed \$parser A AbstractParser instance, or a format name ('XML', 'YAML', 'JSON', 'CSV')
     * @param string \$data The source data to import from
     * @param string \$keyType The type of keys the array uses.
     *
     * @return \$this
     */
    public function importFrom(\$parser, string \$data, string \$keyType = TableMap::$defaultKeyType)
    {
        if (!\$parser instanceof AbstractParser) {
            \$parser = AbstractParser::getParser(\$parser);
        }

        \$this->fromArray(\$parser->toArray(\$data), \$keyType);

        return \$this;
    }
";
    }

    /**
     * Adds a delete() method to remove the object form the datastore.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDelete(string &$script): void
    {
        $this->addDeleteComment($script);
        $this->addDeleteOpen($script);
        $this->addDeleteBody($script);
        $this->addDeleteClose($script);
    }

    /**
     * Adds the comment for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteComment(string &$script): void
    {
        $className = $this->getUnqualifiedClassName();
        $script .= "
    /**
     * Removes this object from datastore and sets delete attribute.
     *
     * @see $className::setDeleted()
     * @see $className::isDeleted()
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */";
    }

    /**
     * Adds the function declaration for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteOpen(string &$script): void
    {
        $script .= "
    public function delete(?ConnectionInterface \$con = null): void
    {";
    }

    /**
     * Adds the function body for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteBody(string &$script): void
    {
        $script .= "
        if (\$this->isDeleted()) {
            throw new PropelException('This object has already been deleted.');
        }

        if (\$con === null) {
            \$con = Propel::getServiceContainer()->getWriteConnection(" . $this->getTableMapClass() . "::DATABASE_NAME);
        }

        \$con->transaction(function () use (\$con) {
            \$deleteQuery = " . $this->getQueryClassName() . "::create()
                ->filterByPrimaryKey(\$this->getPrimaryKey());";
        if ($this->getBuildProperty('generator.objectModel.addHooks')) {
            $script .= "
            \$ret = \$this->preDelete(\$con);";
            // apply behaviors
            $this->applyBehaviorModifier('preDelete', $script, '            ');
            $script .= "
            if (\$ret) {
                \$deleteQuery->delete(\$con);
                \$this->postDelete(\$con);";
            // apply behaviors
            $this->applyBehaviorModifier('postDelete', $script, '                ');
            $script .= "
                \$this->setDeleted(true);
            }";
        } else {
            // apply behaviors
            $this->applyBehaviorModifier('preDelete', $script, '            ');
            $script .= "
            \$deleteQuery->delete(\$con);";
            // apply behaviors
            $this->applyBehaviorModifier('postDelete', $script, '            ');
            $script .= "
            \$this->setDeleted(true);";
        }

        $script .= "
        });";
    }

    /**
     * Adds the function close for the delete function
     *
     * @see addDelete()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDeleteClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds a reload() method to re-fetch the data for this object from the database.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addReload(string &$script): void
    {
        $table = $this->getTable();
        $tableMapClass = $this->getTableMapClass();
        $queryClassName = $this->getQueryClassName();
        $script .= "
    /**
     * Reloads this object from datastore based on primary key and (optionally) resets all associated objects.
     *
     * This will only work if the object has been saved and has a valid primary key set.
     *
     * @param bool \$deep (optional) Whether to also de-associated any related objects.
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con (optional) The ConnectionInterface connection to use.
     *
     * @throws \Propel\Runtime\Exception\PropelException - if this object is deleted, unsaved or doesn't have pk match in db
     *
     * @return void
     */
    public function reload(bool \$deep = false, ?ConnectionInterface \$con = null): void
    {
        if (\$this->isDeleted()) {
            throw new PropelException('Cannot reload a deleted object.');
        }

        if (\$this->isNew()) {
            throw new PropelException('Cannot reload an unsaved object.');
        }

        if (\$con === null) {
            \$con = Propel::getServiceContainer()->getReadConnection({$tableMapClass}::DATABASE_NAME);
        }

        // We don't need to alter the object instance pool; we're just modifying this instance
        // already in the pool.

        \$dataFetcher = {$queryClassName}::create(null, \$this->buildPkeyCriteria())->fetch(\$con);
        \$row = \$dataFetcher->fetch();
        \$dataFetcher->close();
        if (!\$row || \$row === true) {
            throw new PropelException('Cannot find matching row in the database to reload object values.');
        }
        \$this->hydrate(\$row, 0, true, \$dataFetcher->getIndexType()); // rehydrate
";

        // support for lazy load columns
        foreach ($table->getColumns() as $col) {
            if (!$col->isLazyLoad()) {
                continue;
            }
            $clo = $col->getLowercasedName();
            $script .= "
        // Reset the $clo lazy-load column
        \$this->{$clo} = null;
        \$this->{$clo}_isLoaded = false;
";
        }

        $script .= "
        if (\$deep) { // also de-associate any related objects?";

        foreach ($this->fkRelationCodeProducers as $producer) {
            $producer->addOnReloadCode($script);
        }

        foreach ($this->incomingRelationCodeProducers as $producer) {
            $producer->addOnReloadCode($script);
        }

        foreach ($this->crossRelationCodeProducers as $producer) {
            $producer->addOnReloadCode($script);
        }

        $script .= "
        }
    }
";
    }

    /**
     * Adds the methods related to refreshing, saving and deleting the object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addManipulationMethods(string &$script): void
    {
        $this->addReload($script);
        $this->addDelete($script);
        $this->addSave($script);
        $this->addDoSave($script);
        $script .= $this->addDoInsert();
        $script .= $this->addDoUpdate();
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addHashCode(string &$script): void
    {
        $this->declareClass('\RuntimeException');
        $primaryKeyFKNames = [];
        $foreignKeyPKCount = 0;
        foreach ($this->getTable()->getForeignKeys() as $foreignKey) {
            $foreignKeyPKCount += count($foreignKey->getLocalPrimaryKeys());
            if ($foreignKey->getLocalPrimaryKeys()) {
                $primaryKeyFKNames[] = 'a' . $this->getFKPhpNameAffix($foreignKey);
            }
        }

        $script .= "
    /**
     * If the primary key is not null, return the hashcode of the
     * primary key. Otherwise, return the hash code of the object.";

        if ($this->getTable()->hasPrimaryKey() || $foreignKeyPKCount > 0) {
            $script .= "
     *
     * @throws \RuntimeException";
        }

        $script .= "
     *
     * @return string|int Hashcode
     */
    public function hashCode()
    {";
        // use PK if available
        if ($this->getTable()->hasPrimaryKey()) {
            $primaryKeys = $this->getTable()->getPrimaryKey();
            $checks = array_map(fn ($pk) => "\$this->get{$pk->getPhpName()}() !== null", $primaryKeys);
            $offset = '                     ';
            $checkExpression = implode(" &&\n$offset", $checks);
            $script .= "
        \$pkIsValid = $checkExpression;
        
        if (\$pkIsValid) {
            \$json = json_encode(\$this->getPrimaryKey(), JSON_UNESCAPED_UNICODE);
            if (\$json === false) {
                throw new RuntimeException('Failed to encode PK as JSON.');
            }
 
            return crc32(\$json);
        }
";
        }

        // use foreign object hashes if available
        if ($foreignKeyPKCount > 0) {
            $fkNamesString = "['" . implode("', '", $primaryKeyFKNames) . "']";
            $script .= "
        \$fkFieldNames = $fkNamesString;
        \$foreignPksAreValid = true;
        \$primaryKeyFKs = [];
        foreach (\$fkFieldNames as \$fkFieldName) {
            \$fkObject = \$this->\$fkFieldName;
            if (!\$fkObject) {
                \$foreignPksAreValid = false;

                break;
            }
            \$primaryKeyFKs[] = spl_object_hash(\$fkObject);
        }
";
            $script .= "
        if (\$foreignPksAreValid) {
            \$json = json_encode(\$primaryKeyFKs, JSON_UNESCAPED_UNICODE);
            if (\$json === false) {
                throw new RuntimeException('Failed to encode combined PK as JSON.');
            }

            return crc32(\$json);
        }\n";
        }
        $script .= "
        return spl_object_hash(\$this);
    }
";
    }

    /**
     * Adds the correct getPrimaryKey() method for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKey(string &$script): void
    {
        $pkeys = $this->getTable()->getPrimaryKey();
        if (count($pkeys) == 1) {
            $this->addGetPrimaryKeySinglePK($script);
        } elseif (count($pkeys) > 1) {
            $this->addGetPrimaryKeyMultiPK($script);
        } else {
            // no primary key -- this is deprecated, since we don't *need* this method anymore
            $this->addGetPrimaryKeyNoPK($script);
        }
    }

    /**
     * Adds the getPrimaryKey() method for tables that contain a single-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKeySinglePK(string &$script): void
    {
        $table = $this->getTable();
        $pkeys = $table->getPrimaryKey();
        $type = $pkeys[0]->getPhpType();
        $name = $pkeys[0]->getPhpName();

        $script .= "
    /**
     * Returns the primary key for this object (row).
     *
     * @return $type|null
     */
    public function getPrimaryKey()
    {
        return \$this->get{$name}();
    }
";
    }

    /**
     * Adds the setPrimaryKey() method for tables that contain a multi-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKeyMultiPK(string &$script): void
    {
        $columnTypes = array_map(fn (Column $col) => "{$col->resolveQualifiedType()}|null", $this->getTable()->getPrimaryKey());
        $pkType = 'array{' . implode(', ', $columnTypes) . '}';

        $keyColumns = $this->getTable()->getPrimaryKey();
        $names = array_values(array_map(fn ($column) => $column->getPhpName(), $keyColumns));
        $setters = array_map(fn ($index, $name) => "\$pks[{$index}] = \$this->get{$name}();", array_keys($names), $names);
        $settersBlock = implode("\n        ", $setters);
        $script .= "
    /**
     * Returns the composite primary key for this object.
     * The array elements will be in same order as specified in XML.
     *
     * @return $pkType
     */
    public function getPrimaryKey(): array
    {
        \$pks = [];
        {$settersBlock}

        return \$pks;
    }
";
    }

    /**
     * Adds the getPrimaryKey() method for objects that have no primary key.
     * This "feature" is deprecated, since the getPrimaryKey() method is not required
     * by the Persistent interface (or used by the templates). Hence, this method is also
     * deprecated.
     *
     * @deprecated Not needed anymore.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addGetPrimaryKeyNoPK(string &$script): void
    {
        $script .= "
    /**
     * Returns NULL since this table doesn't have a primary key.
     * This method exists only for BC and is deprecated!
     *
     * @return null
     */
    public function getPrimaryKey()
    {
        return null;
    }
";
    }

    /**
     * Adds the correct setPrimaryKey() method for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSetPrimaryKey(string &$script): void
    {
        $pkeys = $this->getTable()->getPrimaryKey();
        if (count($pkeys) == 1) {
            $this->addSetPrimaryKeySinglePK($script);
        } elseif (count($pkeys) > 1) {
            $this->addSetPrimaryKeyMultiPK($script);
        }
    }

    /**
     * Adds the setPrimaryKey() method for tables that contain a single-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSetPrimaryKeySinglePK(string &$script): void
    {
        $pkeys = $this->getTable()->getPrimaryKey();
        $col = $pkeys[0];
        $clo = $col->getLowercasedName();
        $ctype = $col->getPhpType();

        $script .= "
    /**
     * Generic method to set the primary key ($clo column).
     *
     * @param $ctype|null \$key Primary key.
     *
     * @return void
     */
    public function setPrimaryKey(?$ctype \$key = null): void
    {
        \$this->set" . $col->getPhpName() . "(\$key);
    }
";
    }

    /**
     * Adds the setPrimaryKey() method for tables that contain a multi-column primary key.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSetPrimaryKeyMultiPK(string &$script): void
    {
        $script .= "
    /**
     * Set the [composite] primary key.
     *
     * @param array \$keys The elements of the composite key (order must match the order in XML file).
     *
     * @return void
     */
    public function setPrimaryKey(array \$keys): void
    {";
        $i = 0;
        foreach ($this->getTable()->getPrimaryKey() as $pk) {
            $script .= "
        \$this->set" . $pk->getPhpName() . "(\$keys[$i]);";
            $i++;
        }
        $script .= "
    }
";
    }

    /**
     * Adds the isPrimaryKeyNull() method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addIsPrimaryKeyNull(string &$script): void
    {
        $table = $this->getTable();
        $pkeys = $table->getPrimaryKey();
        $checks = array_map(fn ($col) => "\$this->get{$col->getPhpName()}() === null", $pkeys);

        if (count($pkeys) === 1) {
            $returnExpression = $checks[0];
        } elseif ($pkeys) {
            $returnExpression = '(' . implode(') && (', $checks) . ')';
        } else {
            $returnExpression = 'false';
        }

        $script .= "
    /**
     * Returns true if the primary key for this object is null.
     *
     * @return bool
     */
    public function isPrimaryKeyNull(): bool
    {
        return {$returnExpression};
    }
";
    }

    /**
     * Constructs variable name for fkey-related objects.
     *
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    public function getFKVarName(ForeignKey $fk): string
    {
        return 'a' . $this->nameProducer->resolveRelationIdentifier($fk, false);
    }

    /**
     * Constructs variable name for objects which referencing current table by specified foreign key.
     *
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    public function getRefFKCollVarName(ForeignKey $fk): string
    {
        return 'coll' . $this->getRefFKPhpNameAffix($fk, true);
    }

    /**
     * Constructs variable name for single object which references current table by specified foreign key
     * which is ALSO a primary key (hence one-to-one relationship).
     *
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    public function getPKRefFKVarName(ForeignKey $fk): string
    {
        return 'single' . $this->getRefFKPhpNameAffix($fk, false);
    }

    /**
     * Adds the workhourse doSave() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addDoSave(string &$script): void
    {
        $table = $this->getTable();

        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();

        $script .= "
    /**
     * Performs the work of inserting or updating the row in the database.
     *
     * If the object is new, it inserts it; otherwise an update is performed.
     * All related objects are also updated in this method.
     *
     * @see static::save()
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con";
        if ($reloadOnUpdate || $reloadOnInsert) {
            $script .= "
     * @param bool \$skipReload Whether to skip the reload for this object from database.";
        }
        $script .= "
     *
     * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
     */
    protected function doSave(ConnectionInterface \$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload = false' : '') . "): int
    {
        if (\$this->alreadyInSave) {
            return 0;
        }

        \$affectedRows = 0; // initialize var to track total num of affected rows
        \$this->alreadyInSave = true;\n";
        if ($reloadOnInsert || $reloadOnUpdate) {
            $script .= "
        \$reloadObject = false;\n";
        }

        if (count($table->getForeignKeys())) {
            $script .= "
        // We call the save method on the following object(s) if they
        // were passed to this object by their corresponding set
        // method. This object relates to these object(s) by a
        // foreign key reference.\n";

            foreach ($this->fkRelationCodeProducers as $producer) {
                $producer->addDeleteScheduledItemsCode($script);
            }
        }

        $script .= "
        if (\$this->isNew() || \$this->isModified()) {
            // persist changes
            if (\$this->isNew()) {
                \$this->doInsert(\$con);
                \$affectedRows += 1;";
        if ($reloadOnInsert) {
            $script .= "
                if (!\$skipReload) {
                    \$reloadObject = true;
                }";
        }
        $script .= "
            } else {
                \$affectedRows += \$this->doUpdate(\$con);";
        if ($reloadOnUpdate) {
            $script .= "
                if (!\$skipReload) {
                    \$reloadObject = true;
                }";
        }
        $script .= "
            }";

        // We need to rewind any LOB columns
        foreach ($table->getColumns() as $col) {
            $clo = $col->getLowercasedName();
            if ($col->isLobType()) {
                $script .= "
            // Rewind the $clo LOB column, since PDO does not rewind after inserting value.
            if (\$this->$clo !== null && is_resource(\$this->$clo)) {
                rewind(\$this->$clo);
            }\n";
            }
        }

        $script .= "
            \$this->resetModified();
        }\n";

        foreach ($this->crossRelationCodeProducers as $producer) {
            $producer->addDeleteScheduledItemsCode($script);
        }

        foreach ($this->incomingRelationCodeProducers as $producer) {
            $producer->addDeleteScheduledItemsCode($script);
        }

        $script .= "
        \$this->alreadyInSave = false;\n";
        if ($reloadOnInsert || $reloadOnUpdate) {
            $script .= "
        if (\$reloadObject) {
            \$this->reload((bool)\$con);
        }\n";
        }
        $script .= "
        return \$affectedRows;
    }
";
    }

    /**
     * get the doInsert() method code
     *
     * @return string the doInsert() method code
     */
    protected function addDoInsert(): string
    {
        $table = $this->getTable();
        $isMssql = $this->getPlatform() instanceof MssqlPlatform;

        $script = "
    /**
     * Insert the row in the database.
     *
     * @see static::doSave()
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con";
        if ($isMssql && $this->getTable()->getIdMethod() != IdMethod::NO_ID_METHOD) {
            $script .= "
     *
     * @throws \Propel\Runtime\Exception\PropelException";
        }
        if (!$isMssql) {
            $script .= "
     *
     * @throws \RuntimeException
     * @throws \Propel\Runtime\Exception\PropelException";
        }

        $script .= "
     *
     * @return void
     */
    protected function doInsert(ConnectionInterface \$con): void
    {";
        if ($this->getPlatform() instanceof MssqlPlatform) {
            if ($table->hasAutoIncrementPrimaryKey()) {
                $script .= "
        \$this->modifiedColumns[" . $this->getColumnConstant($table->getAutoIncrementPrimaryKey()) . '] = true;';
            }
            $script .= "
        \$criteria = \$this->buildCriteria();";
            if ($this->getTable()->getIdMethod() != IdMethod::NO_ID_METHOD) {
                $script .= $this->addDoInsertBodyWithIdMethod();
            } else {
                $script .= $this->addDoInsertBodyStandard();
            }
        } else {
            $script .= $this->addDoInsertBodyRaw();
        }
        $script .= "
        \$this->setNew(false);
    }
";

        return $script;
    }

    /**
     * @return string
     */
    protected function addDoInsertBodyStandard(): string
    {
        return "
        \$pk = \$criteria->doInsert(\$con);";
    }

    /**
     * @return string
     */
    protected function addDoInsertBodyWithIdMethod(): string
    {
        $table = $this->getTable();
        $script = '';
        foreach ($table->getPrimaryKey() as $col) {
            if (!$col->isAutoIncrement()) {
                continue;
            }
            $colConst = $this->getColumnConstant($col);
            if (!$table->isAllowPkInsert()) {
                $script .= "
        if (\$criteria->keyContainsValue($colConst) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . $colConst . ')');
        }";
                if (!$this->getPlatform()->supportsInsertNullPk()) {
                    $script .= "
        // remove pkey col since this table uses auto-increment and passing a null value for it is not valid
        \$criteria->remove($colConst);";
                }
            } elseif (!$this->getPlatform()->supportsInsertNullPk()) {
                $script .= "
        // remove pkey col if it is null since this table does not accept that
        if (\$criteria->hasUpdateValue($colConst) && !\$criteria->keyContainsValue($colConst) ) {
            \$criteria->remove($colConst);
        }";
            }
        }

        $script .= $this->addDoInsertBodyStandard();

        foreach ($table->getPrimaryKey() as $col) {
            if (!$col->isAutoIncrement()) {
                continue;
            }
            if ($table->isAllowPkInsert()) {
                $script .= "
        if (\$pk !== null) {
            \$this->set" . $col->getPhpName() . "(\$pk);  //[IMV] update autoincrement primary key
        }";
            } else {
                $script .= "
        \$this->set" . $col->getPhpName() . '($pk);  //[IMV] update autoincrement primary key';
            }
        }

        return $script;
    }

    /**
     * Boosts ActiveRecord::doInsert() by doing more calculations at buildtime.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return string
     */
    protected function addDoInsertBodyRaw(): string
    {
        $this->declareClasses(
            '\Propel\Runtime\Propel',
            'PDO',
        );
        $table = $this->getTable();
        /** @var \Propel\Generator\Platform\DefaultPlatform $platform */
        $platform = $this->getPlatform();
        $primaryKeyMethodInfo = '';
        if ($table->getIdMethodParameters()) {
            $params = $table->getIdMethodParameters();
            $imp = $params[0];
            $primaryKeyMethodInfo = $imp->getValue();
        } elseif ($table->getIdMethod() == IdMethod::NATIVE && ($platform->getNativeIdMethod() == PlatformInterface::SEQUENCE || $platform->getNativeIdMethod() == PlatformInterface::SERIAL)) {
            $primaryKeyMethodInfo = $platform->getSequenceName($table);
        }
        $query = 'INSERT INTO ' . $this->quoteIdentifier($table->getName()) . ' (%s) VALUES (%s)';
        $script = "
        \$modifiedColumns = [];
        \$index = 0;";

        foreach ($table->getPrimaryKey() as $column) {
            if (!$column->isAutoIncrement()) {
                continue;
            }
            $constantName = $this->getColumnConstant($column);
            if ($platform->supportsInsertNullPk()) {
                $script .= "
        \$this->modifiedColumns[$constantName] = true;";
            }
            $columnProperty = $column->getLowercasedName();
            if (!$table->isAllowPkInsert()) {
                $script .= "
        if (\$this->{$columnProperty} !== null) {
            throw new PropelException('Cannot insert a value for auto-increment primary key (' . $constantName . ')');
        }";
            } elseif (!$platform->supportsInsertNullPk()) {
                $script .= "
        // add primary key column only if it is not null since this database does not accept that
        if (\$this->{$columnProperty} !== null) {
            \$this->modifiedColumns[$constantName] = true;
        }";
            }
        }

        // if non auto-increment but using sequence, get the id first
        if (!$platform->isNativeIdMethodAutoIncrement() && $table->getIdMethod() === 'native') {
            $column = $table->getFirstPrimaryKeyColumn();
            if (!$column) {
                throw new PropelException('Cannot find primary key column in table `' . $table->getName() . '`.');
            }
            $columnProperty = $column->getLowercasedName();
            $script .= "
        if (\$this->{$columnProperty} === null) {
            try {";
            $script .= $platform->getIdentifierPhp('$this->' . $columnProperty, '$con', $primaryKeyMethodInfo, '                ', $column->getPhpType());
            $script .= "
            } catch (Exception \$e) {
                throw new PropelException('Unable to get sequence id.', 0, \$e);
            }
        }
";
        }

        $script .= "

         // check the columns in natural order for more readable SQL queries";
        foreach ($table->getColumns() as $column) {
            $constantName = $this->getColumnConstant($column);
            $identifier = var_export($this->quoteIdentifier($column->getName()), true);
            $script .= "
        if (\$this->isColumnModified($constantName)) {
            \$modifiedColumns[':p' . \$index++] = $identifier;
        }";
        }

        $script .= "

        \$sql = sprintf(
            '$query',
            implode(', ', \$modifiedColumns),
            implode(', ', array_keys(\$modifiedColumns)),
        );

        try {
            \$stmt = \$con->prepare(\$sql);
            if (!\$stmt) {
                throw new RuntimeException(\"Failed to build PreparedStatement for SQL '\$sql'\");
            }
            foreach (\$modifiedColumns as \$identifier => \$columnName) {
                switch (\$columnName) {";

        $tab = '                        ';
        foreach ($table->getColumns() as $column) {
            $columnNameCase = var_export($this->quoteIdentifier($column->getName()), true);
            $accessValueStatement = $this->getAccessValueStatement($column);
            $bindValueStatement = $platform->getColumnBindingPHP($column, '$identifier', $accessValueStatement, $tab);
            $script .= "
                    case $columnNameCase:$bindValueStatement

                        break;";
        }
        $script .= "
                }
            }
            \$stmt->execute();
        } catch (Exception \$e) {
            Propel::log(\$e->getMessage(), Propel::LOG_ERR);

            throw new PropelException(sprintf('Unable to execute INSERT statement [%s]', \$sql), 0, \$e);
        }
";

        // if auto-increment, get the id after
        if ($platform->isNativeIdMethodAutoIncrement() && $table->getIdMethod() === 'native') {
            $script .= "
        try {";
            $script .= $platform->getIdentifierPhp('$pk', '$con', $primaryKeyMethodInfo);
            $script .= "
        } catch (Exception \$e) {
            throw new PropelException('Unable to get autoincrement id.', 0, \$e);
        }";
            $column = $table->getFirstPrimaryKeyColumn();
            if ($column) {
                $columnName = $column->getPhpName();
                $cast = $column->isNumericType() ? '(int)' : '(string)';
                if ($table->isAllowPkInsert()) {
                    $script .= "
        if (\$pk !== null) {
            \$this->set{$columnName}($cast\$pk);
        }";
                } else {
                    $script .= "
        \$this->set{$columnName}($cast\$pk);";
                }
            }
            $script .= "
";
        }

        return $script;
    }

    /**
     * Get the statement how a column value is accessed in the script.
     *
     * Note that this is not necessarily just the getter. If the value is
     * stored on the model in an encoded format, the statement returned by
     * this method includes the statement to decode the value.
     *
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    protected function getAccessValueStatement(Column $column): string
    {
        $columnName = $column->getLowercasedName();

        if ($column->isUuidBinaryType()) {
            $uuidSwapFlag = $this->getUuidSwapFlagLiteral();

            return "UuidConverter::uuidToBin(\$this->$columnName, $uuidSwapFlag)";
        }

        return "\$this->$columnName";
    }

    /**
     * get the doUpdate() method code
     *
     * @return string the doUpdate() method code
     */
    protected function addDoUpdate(): string
    {
        return "
    /**
     * Update the row in the database.
     *
     * @see static::doSave()
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface \$con
     *
     * @return int Number of updated rows
     */
    protected function doUpdate(ConnectionInterface \$con): int
    {
        \$selectCriteria = \$this->buildPkeyCriteria();
        \$valuesCriteria = \$this->buildCriteria();

        return \$selectCriteria->doUpdate(\$valuesCriteria, \$con);
    }
";
    }

    /**
     * Adds the $alreadyInSave attribute, which prevents attempting to re-save the same object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addAlreadyInSaveAttribute(string &$script): void
    {
        $script .= "
    /**
     * Flag to prevent endless save loop, if this object is referenced
     * by another object which falls in this transaction.
     *
     * @var bool
     */
    protected bool \$alreadyInSave = false;
";
    }

    /**
     * Adds the save() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSave(string &$script): void
    {
        $this->addSaveComment($script);
        $this->addSaveOpen($script);
        $this->addSaveBody($script);
        $this->addSaveClose($script);
    }

    /**
     * Adds the comment for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveComment(string &$script): void
    {
        $table = $this->getTable();
        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();

        $script .= "
    /**
     * Persists this object to the database.
     *
     * If the object is new, it inserts it; otherwise an update is performed.
     * All modified related objects will also be persisted in the doSave()
     * method. This method wraps all precipitate database operations in a
     * single transaction.";
        if ($reloadOnUpdate) {
            $script .= "
     *
     * Since this table was configured to reload rows on update, the object will
     * be reloaded from the database if an UPDATE operation is performed (unless
     * the \$skipReload parameter is TRUE).";
        }
        if ($reloadOnInsert) {
            $script .= "
     *
     * Since this table was configured to reload rows on insert, the object will
     * be reloaded from the database if an INSERT operation is performed (unless
     * the \$skipReload parameter is TRUE).";
        }
        $script .= "
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null \$con";
        if ($reloadOnUpdate || $reloadOnInsert) {
            $script .= "
     * @param bool \$skipReload Whether to skip the reload for this object from database.";
        }
        $script .= "
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return int The number of rows affected by this insert/update and any referring fk objects' save() operations.
     */";
    }

    /**
     * Adds the function declaration for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveOpen(string &$script): void
    {
        $table = $this->getTable();
        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();
        $script .= "
    public function save(?ConnectionInterface \$con = null" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload = false' : '') . "): int
    {";
    }

    /**
     * Adds the function body for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveBody(string &$script): void
    {
        $table = $this->getTable();
        $reloadOnUpdate = $table->isReloadOnUpdate();
        $reloadOnInsert = $table->isReloadOnInsert();

        $script .= "
        if (\$this->isDeleted()) {
            throw new PropelException('You cannot save an object that has been deleted.');
        }

        if (\$this->alreadyInSave) {
            return 0;
        }

        if (\$con === null) {
            \$con = Propel::getServiceContainer()->getWriteConnection(" . $this->getTableMapClass() . "::DATABASE_NAME);
        }

        return \$con->transaction(function () use (\$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload' : '') . ') {';

        if ($this->getBuildProperty('generator.objectModel.addHooks')) {
            // save with runtime hooks
            $script .= "
            \$ret = \$this->preSave(\$con);
            \$isInsert = \$this->isNew();";
            $this->applyBehaviorModifier('preSave', $script, '            ');
            $script .= "
            if (\$isInsert) {
                \$ret = \$ret && \$this->preInsert(\$con);";
            $this->applyBehaviorModifier('preInsert', $script, '                ');
            $script .= "
            } else {
                \$ret = \$ret && \$this->preUpdate(\$con);";
            $this->applyBehaviorModifier('preUpdate', $script, '                ');
            $script .= "
            }
            if (\$ret) {
                \$affectedRows = \$this->doSave(\$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload' : '') . ");
                if (\$isInsert) {
                    \$this->postInsert(\$con);";
            $this->applyBehaviorModifier('postInsert', $script, '                    ');
            $script .= "
                } else {
                    \$this->postUpdate(\$con);";
            $this->applyBehaviorModifier('postUpdate', $script, '                    ');
            $script .= "
                }
                \$this->postSave(\$con);";
            $this->applyBehaviorModifier('postSave', $script, '                ');
            $script .= "
                " . $this->getTableMapClassName() . "::addInstanceToPool(\$this);
            } else {
                \$affectedRows = 0;
            }

            return \$affectedRows;";
        } else {
            // save without runtime hooks
            $script .= "
            \$isInsert = \$this->isNew();";
            $this->applyBehaviorModifier('preSave', $script, '            ');
            if ($this->hasBehaviorModifier('preUpdate')) {
                $script .= "
            if (!\$isInsert) {";
                $this->applyBehaviorModifier('preUpdate', $script, '                ');
                $script .= "
            }";
            }
            if ($this->hasBehaviorModifier('preInsert')) {
                $script .= "
            if (\$isInsert) {";
                $this->applyBehaviorModifier('preInsert', $script, '                ');
                $script .= "
            }";
            }
            $script .= "
            \$affectedRows = \$this->doSave(\$con" . ($reloadOnUpdate || $reloadOnInsert ? ', $skipReload' : '') . ');';
            $this->applyBehaviorModifier('postSave', $script, '            ');
            if ($this->hasBehaviorModifier('postUpdate')) {
                $script .= "
            if (!\$isInsert) {";
                $this->applyBehaviorModifier('postUpdate', $script, '                ');
                $script .= "
            }";
            }
            if ($this->hasBehaviorModifier('postInsert')) {
                $script .= "
            if (\$isInsert) {";
                $this->applyBehaviorModifier('postInsert', $script, '                ');
                $script .= "
            }";
            }
            $script .= "
            " . $this->getTableMapClassName() . "::addInstanceToPool(\$this);

            return \$affectedRows;";
        }

        $script .= "
        });";
    }

    /**
     * Adds the function close for the save method
     *
     * @see addSave()
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addSaveClose(string &$script): void
    {
        $script .= "
    }
";
    }

    /**
     * Adds the ensureConsistency() method to ensure that internal state is correct.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addEnsureConsistency(string &$script): void
    {
        $table = $this->getTable();

        $script .= "
    /**
     * Checks and repairs the internal consistency of the object.
     *
     * This method is executed after an already-instantiated object is re-hydrated
     * from the database. It exists to check any foreign keys to make sure that
     * the objects related to the current object are correct based on foreign key.
     *
     * You can override this method in the stub class, but you should always invoke
     * the base method from the overridden method (i.e. parent::ensureConsistency()),
     * in case your model changes.
     *
     * @return void
     */
    public function ensureConsistency(): void
    {";
        foreach ($table->getColumns() as $col) {
            $clo = $col->getLowercasedName();

            if ($col->isForeignKey()) {
                foreach ($col->getForeignKeys() as $fk) {
                    $tblFK = $table->getDatabase()->getTable($fk->getForeignTableName());
                    $colFK = $tblFK->getColumn($fk->getMappedForeignColumn($col->getName()));
                    $attributeName = $this->getFKVarName($fk);

                    if (!$colFK) {
                        continue;
                    }

                    $script .= "
        if (\$this->" . $attributeName . " !== null && \$this->$clo !== \$this->" . $attributeName . '->get' . $colFK->getPhpName() . "()) {
            \$this->$attributeName = null;
        }";
                }
            }
        }

        $script .= "
    }
";
    }

    /**
     * Adds the copy() method, which (in complex OM) includes the $deepCopy param for making copies of related objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addCopy(string &$script): void
    {
        $this->addCopyInto($script);

        $script .= "
    /**
     * Makes a copy of this object that will be inserted as a new row in table when saved.
     * It creates a new object filling in the simple attributes, but skipping any primary
     * keys that are defined for the table.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param bool \$deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     *
     * @return static Clone of current object.
     */
    public function copy(bool \$deepCopy = false)
    {
        \$clazz = static::class;
        " . $this->buildObjectInstanceCreationCode('$copyObj', '$clazz') . "
        \$this->copyInto(\$copyObj, \$deepCopy);

        return \$copyObj;
    }
";
    }

    /**
     * Adds the copyInto() method, which takes an object and sets contents to match current object.
     * In complex OM this method includes the $deepCopy param for making copies of related objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addCopyInto(string &$script): void
    {
        $table = $this->getTable();
        $ownModelClassName = $this->getTable()->getQualifiedClassName();

        $script .= "
    /**
     * Sets contents of passed object to values from current object.
     *
     * If desired, this method can also make copies of all associated (fkey referrers)
     * objects.
     *
     * @param object \$copyObj An object of $ownModelClassName (or compatible) type.
     * @param bool \$deepCopy Whether to also copy all rows that refer (by fkey) to the current row.
     * @param bool \$makeNew Whether to reset autoincrement PKs and make the object new.
     *
     * @return void
     */
    public function copyInto(object \$copyObj, bool \$deepCopy = false, bool \$makeNew = true): void
    {";

        $autoIncCols = [];
        foreach ($table->getColumns() as $col) {
            /** @var \Propel\Generator\Model\Column $col */
            if ($col->isAutoIncrement()) {
                $autoIncCols[] = $col;
            }
        }

        foreach ($table->getColumns() as $col) {
            if (!in_array($col, $autoIncCols, true)) {
                $script .= "
        \$copyObj->set" . $col->getPhpName() . '($this->get' . $col->getPhpName() . '());';
            }
        }

        // Avoid useless code by checking to see if there are any referrers
        // to this table:
        if (count($table->getReferrers()) > 0) {
            $script .= "

        if (\$deepCopy) {
            // important: temporarily setNew(false) because this affects the behavior of
            // the getter/setter methods for fkey referrer objects.
            \$copyObj->setNew(false);
";
            foreach ($table->getReferrers() as $fk) {
                //HL: commenting out self-referential check below
                //        it seems to work as expected and is probably desirable to have those referrers from same table deep-copied.
                //if ( $fk->getTable()->getName() != $table->getName() ) {

                if ($fk->isLocalPrimaryKey()) {
                    $afx = $this->getRefFKPhpNameAffix($fk, false);
                    $script .= "
            \$relObj = \$this->get$afx();
            if (\$relObj) {
                \$copyObj->set$afx(\$relObj->copy(\$deepCopy));
            }";
                } else {
                    $script .= "
            foreach (\$this->get" . $this->getRefFKPhpNameAffix($fk, true) . "() as \$relObj) {
                if (\$relObj !== \$this) {// ensure that we don't try to copy a reference to ourselves
                    \$copyObj->add" . $this->getRefFKPhpNameAffix($fk) . "(\$relObj->copy(\$deepCopy));
                }
            }";
                }
                // HL: commenting out close of self-referential check
                // } /* if tblFK != table */
            }
            $script .= "
        }
";
        } /* if (count referrers > 0 ) */

        $script .= "
        if (\$makeNew) {
            \$copyObj->setNew(true);";

        // Note: we're no longer resetting non-autoincrement primary keys to default values
        // due to: http://propel.phpdb.org/trac/ticket/618
        foreach ($autoIncCols as $col) {
            $coldefval = $col->getPhpDefaultValue();
            $coldefval = $coldefval === null ? 'null' : var_export($coldefval, true);
            $script .= "
            \$copyObj->set" . $col->getPhpName() . "($coldefval); // this is a auto-increment column, so set to default value";
        }
        $script .= "
        }
    }
";
    }

    /**
     * Adds clear method
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClear(string &$script): void
    {
        $table = $this->getTable();

        $script .= "
    /**
     * Clears the current object, sets all attributes to their default values and removes
     * outgoing references as well as back-references (from other objects to this one. Results probably in a database
     * change of those foreign objects when you call `save` there).
     *
     * @return \$this
     */
    public function clear()
    {";

        foreach ($table->getForeignKeys() as $fk) {
            $attributeName = $this->getFKVarName($fk);
            $relationIdentifier = $fk->getIdentifierReversed();
            $removeMethodCall = $fk->isOneToOne()
            ? "set{$relationIdentifier}(null)"
            : "remove{$relationIdentifier}(\$this)";

            $script .= "
        if (\$this->$attributeName !== null) {
            \$this->$attributeName->$removeMethodCall;
        }";
        }

        foreach ($table->getColumns() as $col) {
            $clo = $col->getLowercasedName();
            $script .= "
        \$this->" . $clo . ' = null;';
            if ($col->isLazyLoad()) {
                $script .= "
        \$this->" . $clo . '_isLoaded = false;';
            }
            if ($col->getType() == PropelTypes::OBJECT || $col->getType() == PropelTypes::PHP_ARRAY) {
                $cloUnserialized = $clo . '_unserialized';

                $script .= "
        \$this->$cloUnserialized = null;";
            }
            if ($col->isSetType()) {
                $cloConverted = $clo . '_converted';

                $script .= "
        \$this->$cloConverted = null;";
            }
        }

        $script .= "
        \$this->alreadyInSave = false;
        \$this->clearAllReferences();";

        if ($this->hasDefaultValues()) {
            $script .= "
        \$this->applyDefaultValues();";
        }

        $script .= "
        \$this->resetModified();
        \$this->setNew(true);
        \$this->setDeleted(false);

        return \$this;
    }
";
    }

    /**
     * Adds clearAllReferences() method which resets all the collections of referencing
     * fk objects.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClearAllReferences(string &$script): void
    {
        $methodBodyCode = $this->buildClearAllReferencesBodyCode();
        if ($methodBodyCode) {
            $methodBodyCode .= "\n";
        }

        $script .= "
    /**
     * Resets all references and back-references to other model objects or collections of model objects.
     *
     * This method is used to reset all php object references (not the actual reference in the database).
     * Necessary for object serialisation.
     *
     * @param bool \$deep Whether to also clear the references on all referrer objects.
     *
     * @return static
     */
    public function clearAllReferences(bool \$deep = false): static
    {{$methodBodyCode}
        return \$this;
    }
";
    }

    /**
     * @return string
     */
    protected function buildClearAllReferencesBodyCode(): string
    {
        $clearRelationReferencesCode = '';
        $vars = [];

        foreach ($this->incomingRelationCodeProducers as $producer) {
            $vars[] = $producer->addClearReferencesCode($clearRelationReferencesCode);
        }

        foreach ($this->crossRelationCodeProducers as $producer) {
            $vars[] = $producer->addClearReferencesCode($clearRelationReferencesCode);
        }

        $methodBodyCode = '';
        if ($clearRelationReferencesCode) {
            $methodBodyCode .= "
        if (\$deep) {{$clearRelationReferencesCode}
        }\n";
        }

        $this->applyBehaviorModifier('objectClearReferences', $methodBodyCode, '        ');

        foreach ($vars as $varName) {
            $methodBodyCode .= "
        \$this->$varName = null;";
        }

        foreach ($this->fkRelationCodeProducers as $producer) {
            $vars[] = $producer->addClearReferencesCode($methodBodyCode);
        }

        return $methodBodyCode;
    }

    /**
     * Adds a magic __toString() method if a string column was defined as primary string
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addPrimaryString(string &$script): void
    {
        foreach ($this->getTable()->getColumns() as $column) {
            if ($column->isPrimaryString()) {
                $script .= "
    /**
     * Return the string representation of this object
     *
     * @return string The value of the '{$column->getName()}' column
     */
    public function __toString(): string
    {
        return (string)\$this->get{$column->getPhpName()}();
    }
";

                return;
            }
        }
        // no primary string column, falling back to default string format
        $script .= "
    /**
     * Return the string representation of this object
     *
     * @return string
     */
    public function __toString()
    {
        return (string)\$this->exportTo(" . $this->getTableMapClassName() . "::DEFAULT_STRING_FORMAT);
    }
";
    }

    /**
     * Adds a magic __call() method.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addMagicCall(string &$script): void
    {
        $behaviorCallScript = '';
        $this->applyBehaviorModifier('objectCall', $behaviorCallScript, '        ');

        $script .= $this->renderTemplate('baseObjectMethodMagicCall', [
            'behaviorCallScript' => $behaviorCallScript,
            'hasGenericMutators' => $this->isAddGenericMutators(),
        ]);
    }

    /**
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    public function getDateTimeClass(Column $column): string
    {
        if (PropelTypes::isPhpObjectType($column->getPhpType())) {
            return $column->getPhpType();
        }

        $dateTimeClass = $this->getBuildProperty('generator.dateTime.dateTimeClass');
        if (!$dateTimeClass) {
            $dateTimeClass = '\DateTime';
        }

        return $dateTimeClass;
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addWriteResource(string &$script)
    {
        $this->declareClass('\RuntimeException');

        $script .= "
    /**
     * @param resource|string|null \$value
     *
     * @throws \RuntimeException
     *
     * @return resource|null
     */
    protected function writeResource(\$value)
    {
        if (!is_string(\$value)) {
            return \$value;
        }
        \$stream = fopen('php://memory', 'r+');
        if (is_bool(\$stream)) {
            throw new RuntimeException('Could not open memory stream');
        }
        fwrite(\$stream, \$value);
        rewind(\$stream);

        return \$stream;
    }\n";
    }
}
