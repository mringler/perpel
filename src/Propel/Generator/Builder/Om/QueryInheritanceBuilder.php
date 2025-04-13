<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om;

use Propel\Generator\Exception\BuildException;
use Propel\Generator\Model\Inheritance;

/**
 * Generates the empty stub query class for use with single table
 * inheritance.
 *
 * This class produces the empty stub class that can be customized with
 * application business logic, custom behavior, etc.
 *
 * @author François Zaninotto
 */
class QueryInheritanceBuilder extends AbstractOMBuilder
{
    /**
     * The current child "object" we are operating on.
     *
     * @var \Propel\Generator\Model\Inheritance|null
     */
    protected $child;

    /**
     * Returns the name of the current class being built.
     *
     * @return string
     */
    public function getUnprefixedClassName(): string
    {
        return $this->getNewStubQueryInheritanceBuilder($this->getChild())->getUnprefixedClassName();
    }

    /**
     * Gets the package for the [base] object classes.
     *
     * @return string
     */
    public function getPackage(): string
    {
        return ($this->getChild()->getPackage() ?: parent::getPackage()) . '.Base';
    }

    /**
     * Gets the namespace for the [base] object classes.
     *
     * @return string|null
     */
    public function getNamespace(): ?string
    {
        $namespace = parent::getNamespace();

        return $namespace ? "$namespace\\Base" : 'Base';
    }

    /**
     * Sets the child object that we're operating on currently.
     *
     * @param \Propel\Generator\Model\Inheritance $child
     *
     * @return void
     */
    public function setChild(Inheritance $child): void
    {
        $this->child = $child;
    }

    /**
     * Returns the child object we're operating on currently.
     *
     * @throws \Propel\Generator\Exception\BuildException
     *
     * @return \Propel\Generator\Model\Inheritance
     */
    public function getChild(): Inheritance
    {
        if (!$this->child) {
            throw new BuildException('The MultiExtendObjectBuilder needs to be told which child class to build (via setChild() method) before it can build the stub class.');
        }

        return $this->child;
    }

    /**
     * Returns classpath to parent class.
     *
     * @return string|null
     */
    protected function getParentClassName(): ?string
    {
        if ($this->getChild()->getAncestor() === null) {
            return $this->getNewStubQueryBuilder($this->getTable())->getUnqualifiedClassName();
        }

        $ancestorClassName = ClassTools::classname($this->getChild()->getAncestor());
        if ($this->getDatabase()->hasTableByPhpName($ancestorClassName)) {
            return $this->getNewStubQueryBuilder($this->getDatabase()->getTableByPhpName($ancestorClassName))->getUnqualifiedClassName();
        }

        // find the inheritance for the parent class
        foreach ($this->getTable()->getChildrenColumn()->getChildren() as $child) {
            if ($child->getClassName() == $ancestorClassName) {
                return $this->getNewStubQueryInheritanceBuilder($child)->getUnqualifiedClassName();
            }
        }

        return null;
    }

    /**
     * Adds class phpdoc comment and opening of class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addClassOpen(string &$script): void
    {
        $table = $this->getTable();
        $tableName = $table->getName();
        $tableDesc = $table->getDescription();

        $baseBuilder = $this->getStubQueryBuilder();
        $this->declareClassFromBuilder($baseBuilder);
        $baseClassName = $this->getParentClassName();

        $script .= "
/**
 * Skeleton subclass for representing a query for one of the subclasses of the '$tableName' table.
 *
 * $tableDesc
 *";
        if ($this->getBuildProperty('generator.objectModel.addTimeStamp')) {
            $now = strftime('%c');
            $script .= "
 * This class was autogenerated by Propel " . $this->getBuildProperty('general.version') . " on:
 *
 * $now
 *";
        }
        $script .= "
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 */
class " . $this->getUnqualifiedClassName() . ' extends ' . $baseClassName . "
{
";
    }

    /**
     * Specifies the methods that are added as part of the stub object class.
     *
     * By default there are no methods for the empty stub classes; override this
     * method if you want to change that behavior.
     *
     * @see ObjectBuilder::addClassBody()
     *
     * @param string $script
     *
     * @return void
     */
    protected function addClassBody(string &$script): void
    {
        $this->declareClassFromBuilder($this->getTableMapBuilder());
        $this->declareClasses(
            '\Propel\Runtime\Connection\ConnectionInterface',
            '\Propel\Runtime\ActiveQuery\Criteria',
        );
        $this->addFactory($script);
        $this->addPreSelect($script);
        $this->addPreUpdate($script);
        $this->addPreDelete($script);
        $this->addDoDeleteAll($script);
    }

    /**
     * Adds the factory for this object.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    protected function addFactory(string &$script): void
    {
        $builder = $this->getNewStubQueryInheritanceBuilder($this->getChild());
        $this->declareClassFromBuilder($builder, 'Child');
        $classname = $builder->getClassName();
        $script .= "
    /**
     * Returns a new " . $classname . " object.
     *
     * @param string \$modelAlias The alias of a model in the query
     * @param Criteria \$criteria Optional Criteria to build the query from
     *
     * @return " . $classname . "
     */
    public static function create(?string \$modelAlias = null, ?Criteria \$criteria = null): Criteria
    {
        if (\$criteria instanceof " . $classname . ") {
            return \$criteria;
        }
        \$query = new " . $classname . "();
        if (\$modelAlias !== null) {
            \$query->setModelAlias(\$modelAlias);
        }
        if (\$criteria !== null) {
            \$query->mergeWith(\$criteria);
        }

        return \$query;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addPreSelect(string &$script): void
    {
        $child = $this->getChild();

        $script .= "
    /**
     * Filters the query to target only " . $child->getClassName() . " objects.
     */
    public function preSelect(ConnectionInterface \$con): void
    {
        " . $this->getClassKeyCondition() . "
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addPreUpdate(string &$script): void
    {
        $child = $this->getChild();

        $script .= "
    /**
     * Filters the query to target only " . $child->getClassName() . " objects.
     *
     * @return int|null
     */
    public function preUpdate(&\$values, ConnectionInterface \$con, \$forceIndividualSaves = false): ?int
    {
        " . $this->getClassKeyCondition() . "

        return null;
    }
";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addPreDelete(string &$script): void
    {
        $child = $this->getChild();

        $script .= "
    /**
     * Filters the query to target only " . $child->getClassName() . " objects.
     *
     * @return int|null
     */
    public function preDelete(ConnectionInterface \$con): ?int
    {
        " . $this->getClassKeyCondition() . "

        return null;
    }
";
    }

    /**
     * @return string
     */
    protected function getClassKeyCondition(): string
    {
        $child = $this->getChild();
        $value = "{$this->getTableMapClassName()}::CLASSKEY_{$child->getConstantSuffix()}";
        $col = $child->getColumn();

        return "\$this->addUsingOperator(\$this->resolveLocalColumnByName('{$col->getName()}'), $value);";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    protected function addDoDeleteAll(string &$script): void
    {
        $child = $this->getChild();

        $script .= "
    /**
     * Issue a DELETE query based on the current ModelCriteria deleting all rows in the table
     * Having the " . $child->getClassName() . " class.
     * This method is called by ModelCriteria::deleteAll() inside a transaction
     *
     * @param ConnectionInterface \$con a connection object
     *
     * @return int The number of deleted rows
     */
    public function doDeleteAll(?ConnectionInterface \$con = null): int
    {
        // condition on class key is already added in preDelete()
        return parent::delete(\$con);
    }
";
    }

    /**
     * Closes class.
     *
     * @param string $script
     *
     * @return void
     */
    protected function addClassClose(string &$script): void
    {
        $script .= "
}
";
    }
}
