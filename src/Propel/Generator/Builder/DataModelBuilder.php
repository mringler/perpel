<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder;

use Propel\Common\Pluralizer\PluralizerInterface;
use Propel\Generator\Builder\BuilderFactory\BuilderFactory;
use Propel\Generator\Builder\BuilderFactory\CodeBuilderStore;
use Propel\Generator\Builder\Om\AbstractObjectBuilder;
use Propel\Generator\Builder\Om\AbstractOMBuilder;
use Propel\Generator\Builder\Om\MultiExtendObjectBuilder;
use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Builder\Om\ObjectCollectionBuilder;
use Propel\Generator\Builder\Om\QueryBuilder;
use Propel\Generator\Builder\Om\TableMapBuilder;
use Propel\Generator\Builder\Util\NameProducer;
use Propel\Generator\Builder\Util\ReferencedClasses;
use Propel\Generator\Config\GeneratorConfigInterface;
use Propel\Generator\Exception\LogicException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Inheritance;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Model\Table;
use Propel\Generator\Platform\PlatformInterface;

/**
 * This is the base class for any builder class that is using the data model.
 *
 * This could be extended by classes that build SQL DDL, PHP classes, configuration
 * files, input forms, etc.
 *
 * The GeneratorConfig needs to be set on this class in order for the builders
 * to be able to access the propel generator build properties. You should be
 * safe if you always use the GeneratorConfig to get a configured builder class
 * anyway.
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
abstract class DataModelBuilder
{
    /**
     * The current table.
     *
     * @var \Propel\Generator\Model\Table
     */
    private Table $table;

    /**
     * The generator config object holding build properties, etc.
     *
     * @var \Propel\Generator\Config\GeneratorConfigInterface|null
     */
    private ?GeneratorConfigInterface $generatorConfig = null;

    /**
     * @var \Propel\Generator\Builder\BuilderFactory\BuilderFactory
     */
    protected $builderFactory;

    /**
     * @var \Propel\Generator\Builder\BuilderFactory\CodeBuilderStore|null
     */
    protected $codeBuilderStore;

    /**
     * @var \Propel\Generator\Builder\Util\NameProducer|null
     */
    protected $nameProducer;

    /**
     * @var \Propel\Generator\Builder\Util\ReferencedClasses
     */
    protected $referencedClasses;

    /**
     * An array of warning messages that can be retrieved for display.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * The platform class
     *
     * @var \Propel\Generator\Platform\PlatformInterface|null
     */
    protected ?PlatformInterface $platform = null;

    /**
     * @param \Propel\Generator\Model\Table $table
     * @param \Propel\Generator\Builder\Util\ReferencedClasses|null $referencedClasses
     */
    public function __construct(Table $table, ?ReferencedClasses $referencedClasses)
    {
        $this->setTable($table);
        $this->builderFactory = new BuilderFactory();
        $this->referencedClasses = $referencedClasses;
    }

    /**
     * @param \Propel\Generator\Model\Table $table
     * @param \Propel\Generator\Config\GeneratorConfigInterface|null $generatorConfig
     *
     * @return void
     */
    protected function init(Table $table, ?GeneratorConfigInterface $generatorConfig): void
    {
        $this->table = $table;
        if (!$generatorConfig) {
            return;
        }
        $this->generatorConfig = $generatorConfig;
        $this->nameProducer = new NameProducer($generatorConfig->getConfiguredPluralizer());
        $this->builderFactory->setGeneratorConfig($generatorConfig);
        $this->referencedClasses->setGeneratorConfig($generatorConfig);
        $this->codeBuilderStore = new CodeBuilderStore($table, $this->builderFactory);
    }

    /**
     * Sets the GeneratorConfig object.
     *
     * @param \Propel\Generator\Config\GeneratorConfigInterface $generatorConfig
     *
     * @return void
     */
    public function setGeneratorConfig(GeneratorConfigInterface $generatorConfig): void
    {
        $this->init($this->table, $generatorConfig);
    }

    /**
     * Sets the table for this builder.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return void
     */
    public function setTable(Table $table): void
    {
        $this->init($table, $this->generatorConfig);
    }

    /**
     * Gets the GeneratorConfig object.
     *
     * @return \Propel\Generator\Config\GeneratorConfigInterface|null
     */
    public function getGeneratorConfig(): ?GeneratorConfigInterface
    {
        return $this->generatorConfig;
    }

    /**
     * Returns the current Table object.
     *
     * @return \Propel\Generator\Model\Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * Get a specific configuration property.
     *
     * The name of the requested property must be given as a string, representing its hierarchy in the configuration
     * array, with each level separated by a dot. I.e.:
     * <code> $config['database']['adapter']['mysql']['tableType']</code>
     * is expressed by:
     * <code>'database.adapter.mysql.tableType</code>
     *
     * @param string $name
     *
     * @return string|null
     */
    public function getBuildProperty(string $name): ?string
    {
        if ($this->getGeneratorConfig()) {
            return $this->getGeneratorConfig()->getConfigProperty($name);
        }

        return null;
    }

    /**
     * Convenience method to return the Platform class for this table (database).
     *
     * @return \Propel\Generator\Platform\PlatformInterface|null
     */
    public function getPlatform(): ?PlatformInterface
    {
        if ($this->platform === null) {
            // try to load the platform from the table
            $table = $this->table;
            $database = $table->getDatabase();
            if ($database) {
                $this->setPlatform($database->getPlatform());
            }
        }

        if (!$this->table->isIdentifierQuotingEnabled()) {
            $this->platform->setIdentifierQuoting(false);
        }

        return $this->platform;
    }

    /**
     * Convenience method to return the Platform class for this table (database).
     *
     * @throws \Propel\Generator\Exception\LogicException
     *
     * @return \Propel\Generator\Platform\PlatformInterface
     */
    public function getPlatformOrFail(): PlatformInterface
    {
        $platform = $this->getPlatform();
        if ($platform === null) {
            throw new LogicException('Platform is not set');
        }

        return $platform;
    }

    /**
     * Platform setter
     *
     * @param \Propel\Generator\Platform\PlatformInterface $platform
     *
     * @return void
     */
    public function setPlatform(PlatformInterface $platform): void
    {
        $this->platform = $platform;
    }

    /**
     * Returns new or existing Pluralizer class.
     *
     * @throws \Propel\Generator\Exception\LogicException
     *
     * @return \Propel\Common\Pluralizer\PluralizerInterface
     */
    public function getPluralizer(): PluralizerInterface
    {
        if (!$this->nameProducer) {
            throw new LogicException('Pluralzier is not available before generator config is set.');
        }

        return $this->nameProducer->getPluralizer();
    }

    /**
     * Convenience method to return the database for current table.
     *
     * @return \Propel\Generator\Model\Database|null
     */
    public function getDatabase(): ?Database
    {
        return $this->getTable()->getDatabase();
    }

    /**
     * Convenience method to return the database for current table.
     *
     * @return \Propel\Generator\Model\Database
     */
    public function getDatabaseOrFail(): Database
    {
        return $this->getTable()->getDatabaseOrFail();
    }

    /**
     * Pushes a message onto the stack of warnings.
     *
     * @param string $msg The warning message.
     *
     * @return void
     */
    protected function warn(string $msg): void
    {
        $this->warnings[] = $msg;
    }

    /**
     * Gets array of warning messages.
     *
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Quotes identifier based on $this->getTable()->isIdentifierQuotingEnabled.
     *
     * @param string $text
     *
     * @return string
     */
    public function quoteIdentifier(string $text): string
    {
        if ($this->getTable()->isIdentifierQuotingEnabled()) {
            return $this->getPlatform()->doQuoting($text);
        }

        return $text;
    }

    /**
     * Returns the name of the current class being built, with a possible prefix.
     *
     * @see OMBuilder#getClassName()
     *
     * @param string $identifier
     *
     * @return string
     */
    public function prefixClassName(string $identifier): string
    {
        return $this->getBuildProperty('generator.objectModel.classPrefix') . $identifier;
    }

    /**
     * Turn array into string representation.
     *
     * Compared to var_export, this uses brackets and no newlines.
     *
     * arrayToString([['a', 42], ['b']]) => '[['a', 42], ['b']]'
     *
     * @param array<array|mixed> $arr
     *
     * @return string
     */
    protected function arrayToString(array $arr): string
    {
        $cb = fn ($item) => is_array($item) ? $this->arrayToString($item) : var_export($item, true);
        $result = array_map($cb, $arr);

        return '[' . implode(', ', $result) . ']';
    }

    /**
     * Shortcut method to return the [stub] query classname for current table.
     * This is the classname that is used whenever object or tablemap classes want
     * to invoke methods of the query classes.
     *
     * @param bool $fqcn
     *
     * @return string (e.g. 'Myquery')
     */
    public function getQueryClassName(bool $fqcn = false): string
    {
        $queryStubBuilder = $this->codeBuilderStore->getStubQueryBuilder();

        return $this->referencedClasses->getInternalNameOfBuilderResultClass($queryStubBuilder, $fqcn);
    }

    /**
     * @param bool $fqcn
     *
     * @return string (e.g. 'MyTable' or 'ChildMyTable')
     */
    public function getObjectClassName(bool $fqcn = false): string
    {
        $stubObjectBuilder = $this->codeBuilderStore->getStubObjectBuilder();

        return $this->referencedClasses->getInternalNameOfBuilderResultClass($stubObjectBuilder, $fqcn);
    }

    /**
     * Returns the object classname for current table.
     * This is the classname that is used whenever object or tablemap classes want
     * to invoke methods of the object classes.
     *
     * @param bool $fqcn
     *
     * @return string (e.g. 'MyTable' or 'ChildMyTable')
     */
    public function registerOwnClassIdentifier(bool $fqcn = false): string
    {
        $stubObjectBuilder = $this->codeBuilderStore->getStubObjectBuilder();

        return $this->referencedClasses->getInternalNameOfBuilderResultClass($stubObjectBuilder, $fqcn);
    }

    /**
     * Returns always the final unqualified object class name. This is only useful for documentation/phpdoc,
     * not in the actual code.
     *
     * @return string
     */
    public function getObjectName(): string
    {
        return $this->codeBuilderStore->getStubObjectBuilder()->getUnqualifiedClassName();
    }

    /**
     * Returns the tableMap classname for current table.
     * This is the classname that is used whenever object or tablemap classes want
     * to invoke methods of the object classes.
     *
     * @param bool $fqcn
     *
     * @return string (e.g. 'My')
     */
    public function getTableMapClassName(bool $fqcn = false): string
    {
        return $this->getClassNameFromBuilder($this->getTableMapBuilder(), $fqcn);
    }

    /**
     * Returns new or existing Object builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\ObjectBuilder
     */
    public function getObjectBuilder(): ObjectBuilder
    {
        return $this->codeBuilderStore->getObjectBuilder();
    }

    /**
     * Returns new or existing stub Object builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\AbstractObjectBuilder
     */
    public function getStubObjectBuilder(): AbstractObjectBuilder
    {
        return $this->codeBuilderStore->getStubObjectBuilder();
    }

    /**
     * Returns new or existing Query builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function getQueryBuilder(): AbstractOMBuilder
    {
        return $this->codeBuilderStore->getQueryBuilder();
    }

    /**
     * Returns new or existing stub Query builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function getStubQueryBuilder(): AbstractOMBuilder
    {
        return $this->codeBuilderStore->getStubQueryBuilder();
    }

    /**
     * Returns new or existing Object builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\TableMapBuilder
     */
    public function getTableMapBuilder(): TableMapBuilder
    {
        return $this->codeBuilderStore->getTableMapBuilder();
    }

    /**
     * Returns new or existing stub Interface builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function getInterfaceBuilder(): AbstractOMBuilder
    {
        return $this->codeBuilderStore->getInterfaceBuilder();
    }

    /**
     * Returns new or existing stub child object builder class for this table.
     *
     * @return \Propel\Generator\Builder\Om\MultiExtendObjectBuilder
     */
    public function getMultiExtendObjectBuilder(): MultiExtendObjectBuilder
    {
        return $this->codeBuilderStore->getMultiExtendObjectBuilder();
    }

    /**
     * Returns new Query Inheritance builder class for this table.
     *
     * @param \Propel\Generator\Model\Inheritance $child
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function getNewQueryInheritanceBuilder(Inheritance $child): AbstractOMBuilder
    {
        return $this->codeBuilderStore->createQueryInheritanceBuilder($child);
    }

    /**
     * Returns new stub Query Inheritance builder class for this table.
     *
     * @param \Propel\Generator\Model\Inheritance $child
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function getNewStubQueryInheritanceBuilder(Inheritance $child): AbstractOMBuilder
    {
        return $this->codeBuilderStore->createStubQueryInheritanceBuilder($child);
    }

    /**
     * Convenience method to return a NEW Object class builder instance.
     *
     * This is used very frequently from the tableMap and object builders to get
     * an object builder for a RELATED table.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return \Propel\Generator\Builder\Om\ObjectBuilder
     */
    public function getNewObjectBuilder(Table $table): ObjectBuilder
    {
        return $this->builderFactory->createObjectBuilder($table);
    }

    /**
     * Convenience method to return a NEW Object stub class builder instance.
     *
     * This is used from the query builders to get
     * an object builder for a RELATED table.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return \Propel\Generator\Builder\Om\AbstractObjectBuilder
     */
    public function getNewStubObjectBuilder(Table $table): AbstractObjectBuilder
    {
        return $this->builderFactory->createStubObjectBuilder($table);
    }

    /**
     * Convenience method to return a NEW query class builder instance.
     *
     * This is used from the query builders to get
     * a query builder for a RELATED table.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return \Propel\Generator\Builder\Om\QueryBuilder
     */
    public function getNewQueryBuilder(Table $table): QueryBuilder
    {
        return $this->builderFactory->createQueryBuilder($table);
    }

    /**
     * Convenience method to return a NEW query stub class builder instance.
     *
     * This is used from the query builders to get
     * a query builder for a RELATED table.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return \Propel\Generator\Builder\Om\AbstractOMBuilder
     */
    public function getNewStubQueryBuilder(Table $table): AbstractOMBuilder
    {
        return $this->builderFactory->createStubQueryBuilder($table);
    }

    /**
     * Returns new stub Query Inheritance builder class for this table.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return \Propel\Generator\Builder\Om\TableMapBuilder
     */
    public function getNewTableMapBuilder(Table $table): TableMapBuilder
    {
        return $this->builderFactory->createTableMapBuilder($table);
    }

    /**
     * Returns new stub Query Inheritance builder class for this table.
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return \Propel\Generator\Builder\Om\ObjectCollectionBuilder
     */
    public function getNewObjectCollectionBuilder(Table $table): ObjectCollectionBuilder
    {
        return $this->builderFactory->createObjectCollectionBuilder($table);
    }

    /**
     * This declares the class use and returns the correct name to use (short classname, Alias, or FQCN)
     *
     * @param \Propel\Generator\Builder\Om\AbstractOMBuilder $builder
     * @param bool $fqcn true to return the $fqcn classname
     *
     * @return string ClassName, Alias or FQCN
     */
    public function getClassNameFromBuilder(AbstractOMBuilder $builder, bool $fqcn = false): string
    {
        return $this->referencedClasses->getInternalNameOfBuilderResultClass($builder, $fqcn);
    }

    /**
     * This declares the class use and returns the correct name to use
     *
     * @param \Propel\Generator\Model\Table $table
     *
     * @return string
     */
    public function getClassNameFromTable(Table $table): string
    {
        return $this->referencedClasses->getInternalNameOfTable($table);
    }

    /**
     * Declare a class to be use and return its name or its alias
     *
     * @param string $class the class name
     * @param string $namespace the namespace
     * @param string|bool|null $alias the alias wanted, if set to True, it automatically adds an alias when needed
     *
     * @return string The class name or its alias
     */
    public function declareClassNamespace(string $class, string $namespace = '', $alias = false): string
    {
        return $this->referencedClasses->registerSimpleClassName($class, $namespace, $alias);
    }

    /**
     * Declare a use statement for a $class with a $namespace and an $aliasPrefix
     * This return the short ClassName or an alias
     *
     * @param string $class the class
     * @param string $namespace the namespace
     * @param string|bool|null $aliasPrefix optionally an alias or True to force an automatic alias prefix (Base or Child)
     *
     * @return string the short ClassName or an alias
     */
    public function declareClassNamespacePrefix(string $class, string $namespace = '', $aliasPrefix = false): string
    {
        return $this->referencedClasses->registerSimpleClassNameWithPrefix($class, $namespace, $aliasPrefix);
    }

    /**
     * Declare a Fully qualified classname with an $aliasPrefix
     * This return the short ClassName to use or an alias
     *
     * @param string $fullyQualifiedClassName the fully qualified classname
     * @param string|bool|null $aliasPrefix optionally an alias or True to force an automatic alias prefix (Base or Child)
     *
     * @return string the short ClassName or an alias
     */
    public function declareClass(string $fullyQualifiedClassName, $aliasPrefix = false): string
    {
        return $this->referencedClasses->registerClassByFullyQualifiedName($fullyQualifiedClassName, $aliasPrefix);
    }

    /**
     * @param \Propel\Generator\Builder\Om\AbstractOMBuilder $builder
     * @param string|bool $aliasPrefix the prefix for the Alias or True for auto generation of the Alias
     *
     * @return string
     */
    public function declareClassFromBuilder(AbstractOMBuilder $builder, $aliasPrefix = false): string
    {
        return $this->referencedClasses->registerBuilderResultClass($builder, $aliasPrefix);
    }

    /**
     * @return void
     */
    public function declareClasses(): void
    {
        $args = func_get_args();
        foreach ($args as $class) {
            $this->declareClass($class);
        }
    }

    /**
     * Get the list of declared classes for a given $namespace or all declared classes
     *
     * @param string|null $namespace the namespace or null
     *
     * @return array list of declared classes
     */
    public function getDeclaredClasses(?string $namespace = null): array
    {
        return $this->referencedClasses->getDeclaredClasses($namespace);
    }

    /**
     * @param \Propel\Generator\Model\Column $column
     *
     * @return string
     */
    public function resolveColumnDateTimeClass(Column $column): string
    {
        if (PropelTypes::isPhpObjectType($column->getPhpType())) {
            return $column->getPhpType();
        }

        return $this->getBuildProperty('generator.dateTime.dateTimeClass') ?: '\DateTime';
    }
}
