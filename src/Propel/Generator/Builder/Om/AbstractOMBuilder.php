<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om;

use Propel\Common\Util\PathTrait;
use Propel\Generator\Builder\DataModelBuilder;
use Propel\Generator\Builder\Util\PropelTemplate;
use Propel\Generator\Builder\Util\ReferencedClasses;
use Propel\Generator\Exception\InvalidArgumentException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Table;
use Propel\Generator\Model\VendorInfo;

/**
 * Baseclass for OM-building classes.
 *
 * OM-building classes are those that build a PHP (or other) class to service
 * a single table. This includes Entity classes, Map classes,
 * Node classes, Nested Set classes, etc.
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
abstract class AbstractOMBuilder extends DataModelBuilder
{
    use PathTrait;

    /**
     * @param \Propel\Generator\Model\Table $table
     */
    public function __construct(Table $table)
    {
        parent::__construct($table, new ReferencedClasses($this));
    }

    /**
     * Builds the PHP source for current class and returns it as a string.
     *
     * This is the main entry point and defines a basic structure that classes should follow.
     * In most cases this method will not need to be overridden by subclasses. This method
     * does assume that the output language is PHP code, so it will need to be overridden if
     * this is not the case.
     *
     * @return string The resulting PHP sourcecode.
     */
    public function build(): string
    {
        $this->validateModel();
        $this->declareClass($this->getFullyQualifiedClassName());

        $script = '';
        $this->addClassOpen($script);
        $this->addClassBody($script);
        $this->addClassClose($script);

        $ignoredNamespace = ltrim((string)$this->getNamespace(), '\\');
        $useStatements = $this->buildUseStatements($ignoredNamespace ?: 'namespace');
        if ($useStatements) {
            $script = $useStatements . $script;
        }

        $namespaceStatement = $this->buildNamespaceStatement();
        if ($namespaceStatement) {
            $script = $namespaceStatement . $script;
        }

        $script = "<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

" . $script;

        return $this->clean($script);
    }

    /**
     * Validates the current table to make sure that it won't
     * result in generated code that will not parse.
     *
     * This method may emit warnings for code which may cause problems
     * and will throw exceptions for errors that will definitely cause
     * problems.
     *
     * @return void
     */
    protected function validateModel(): void
    {
        // Validation is currently only implemented in the subclasses.
    }

    /**
     * Creates a $obj = new Book(); code snippet. Can be used by frameworks, for instance, to
     * extend this behavior, e.g. initialize the object after creating the instance or so.
     *
     * @param string $objName
     * @param string $clsName
     *
     * @return string Some code
     */
    public function buildObjectInstanceCreationCode(string $objName, string $clsName): string
    {
        return "$objName = new $clsName();";
    }

    /**
     * Returns the qualified (prefixed) classname that is being built by the current class.
     * This method must be implemented by child classes.
     *
     * @return string
     */
    abstract public function getUnprefixedClassName(): string;

    /**
     * Returns the unqualified classname (e.g. Book)
     *
     * @return string
     */
    public function getUnqualifiedClassName(): string
    {
        return $this->getUnprefixedClassName();
    }

    /**
     * Returns the qualified classname (e.g. Model\Book)
     *
     * @return string
     */
    public function getQualifiedClassName(): string
    {
        $namespace = $this->getNamespace();
        if ($namespace) {
            return $namespace . '\\' . $this->getUnqualifiedClassName();
        }

        return $this->getUnqualifiedClassName();
    }

    /**
     * Returns the fully qualified classname (e.g. \Model\Book)
     *
     * @return string
     */
    public function getFullyQualifiedClassName(): string
    {
        return '\\' . $this->getQualifiedClassName();
    }

    /**
     * Returns FQCN alias of getFullyQualifiedClassName
     *
     * @return string
     */
    public function getClassName(): string
    {
        return $this->getFullyQualifiedClassName();
    }

    /**
     * Gets the dot-path representation of current class being built.
     *
     * @return string
     */
    public function getClasspath(): string
    {
        if ($this->getPackage()) {
            return $this->getPackage() . '.' . $this->getUnqualifiedClassName();
        }

        return $this->getUnqualifiedClassName();
    }

    /**
     * Gets the full path to the file for the current class.
     *
     * @return string
     */
    public function getClassFilePath(): string
    {
        return ClassTools::createFilePath($this->getPackagePath(), $this->getUnqualifiedClassName());
    }

    /**
     * Gets package name for this table.
     * This is overridden by child classes that have different packages.
     *
     * @return string|null
     */
    public function getPackage(): ?string
    {
        $pkg = ($this->getTable()->getPackage() ?: $this->getDatabaseOrFail()->getPackage());
        if (!$pkg) {
            $pkg = (string)$this->getBuildProperty('generator.targetPackage');
        }

        return $pkg;
    }

    /**
     * Returns filesystem path for current package.
     *
     * @return string
     */
    public function getPackagePath(): string
    {
        $pkg = (string)$this->getPackage();

        if (strpos($pkg, '/') !== false) {
            $pkg = (string)preg_replace('#\.(map|om)$#', '/\1', $pkg);
            $pkg = (string)preg_replace('#\.(Map|Om)$#', '/\1', $pkg);

            return $pkg;
        }

        $path = $pkg;

        $path = str_replace('...', '$$/', $path);
        $path = strtr(ltrim($path, '.'), '.', '/');
        $path = str_replace('$$/', '../', $path);

        return $path;
    }

    /**
     * Returns the user-defined namespace for this table,
     * or the database namespace otherwise.
     *
     * @return string|null Currently returns null in some cases - should be fixed
     */
    public function getNamespace(): ?string
    {
        return $this->getTable()->getNamespace();
    }

    /**
     * Returns the user-defined namespace for this table,
     * or the database namespace otherwise.
     *
     * @return string
     */
    public function getNamespaceOrFail(): string
    {
        return $this->getTable()->getNamespaceOrFail();
    }

    /**
     * return the string for the class namespace
     *
     * @return string|null
     */
    public function buildNamespaceStatement(): ?string
    {
        $namespace = $this->getNamespace();

        return $namespace ? "namespace $namespace;\n\n" : null;
    }

    /**
     * Build "use" block of class.
     *
     * @param string|null $ignoredNamespace the ignored namespace
     *
     * @return string
     */
    public function buildUseStatements(?string $ignoredNamespace = null): string
    {
        $ownNamespace = $this->getNamespace();
        $ownClassName = $this->getUnqualifiedClassName();

        return $this->referencedClasses->buildUseStatements($ownNamespace, $ownClassName, $ignoredNamespace);
    }

    /**
     * Get the column constant name (e.g. TableMapName::COLUMN_NAME).
     *
     * @param \Propel\Generator\Model\Column $col The column we need a name for.
     * @param string|null $classname The TableMap classname to use.
     *
     * @return string If $classname is provided, then will return $classname::COLUMN_NAME; if not, then the TableMapName is looked up for current table to yield $currTableTableMap::COLUMN_NAME.
     */
    public function getColumnConstant(Column $col, ?string $classname = null): string
    {
        if ($classname === null) {
            return $this->getBuildProperty('generator.objectModel.classPrefix') . $col->getFQConstantName();
        }

        // was it overridden in schema.xml ?
        if ($col->getTableMapName()) {
            $const = strtoupper($col->getTableMapName());
        } else {
            $const = strtoupper($col->getName());
        }

        return $classname . '::' . Column::CONSTANT_PREFIX . $const;
    }

    /**
     * Convenience method to get the default Join Type for a relation.
     * If the key is required, an INNER JOIN will be returned, else a LEFT JOIN will be suggested,
     * unless the schema is provided with the DefaultJoin attribute, which overrules the default Join Type
     *
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    protected function getJoinType(ForeignKey $fk): string
    {
        $defaultJoin = $fk->getDefaultJoin();
        if ($defaultJoin) {
            return "'" . $defaultJoin . "'";
        }

        if ($fk->isLocalColumnsRequired()) {
            return 'Criteria::INNER_JOIN';
        }

        return 'Criteria::LEFT_JOIN';
    }

    /**
     * Gets the PHP method name affix to be used for fkeys for the current table (not referrers to this table).
     *
     * The difference between this method and the getRefFKPhpNameAffix() method is that in this method the
     * classname in the affix is the foreign table classname.
     *
     * @param \Propel\Generator\Model\ForeignKey $fk The local FK that we need a name for.
     * @param bool $plural Whether the php name should be plural (e.g. initRelatedObjs() vs. addRelatedObj()
     *
     * @return string
     */
    public function getFKPhpNameAffix(ForeignKey $fk, bool $plural = false): string
    {
        return $this->nameProducer->resolveRelationIdentifier($fk, $plural);
    }

    /**
     * Gets the "RelatedBy*" suffix (if needed) that is attached to method and variable names.
     *
     * The related by suffix is based on the local columns of the foreign key. If there is more than
     * one column in a table that points to the same foreign table, then a 'RelatedByLocalColName' suffix
     * will be appended.
     *
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    protected static function getRelatedBySuffix(ForeignKey $fk): string
    {
        return $fk->buildIdentifierRelatedBySuffix();
    }

    /**
     * Gets the PHP method name affix to be used for referencing foreign key methods and variable names (e.g. set????(), $coll???).
     *
     * The difference between this method and the getFKPhpNameAffix() method is that in this method the
     * classname in the affix is the classname of the local fkey table.
     *
     * @param \Propel\Generator\Model\ForeignKey $fk The referrer FK that we need a name for.
     * @param bool $plural Whether the php name should be plural (e.g. initRelatedObjs() vs. addRelatedObj()
     *
     * @return string|null
     */
    public function getRefFKPhpNameAffix(ForeignKey $fk, bool $plural = false): ?string
    {
        return $this->nameProducer->buildForeignKeyBackReferenceNameAffix($fk, $plural);
    }

    /**
     * @param \Propel\Generator\Model\ForeignKey $fk
     *
     * @return string
     */
    protected static function getRefRelatedBySuffix(ForeignKey $fk): string
    {
        return $fk->buildIdentifierReversedRelatedBySuffix();
    }

    /**
     * Checks whether any registered behavior on that table has a modifier for a hook
     *
     * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
     * @param string $modifier The name of the modifier object providing the method in the behavior
     *
     * @return bool
     */
    public function hasBehaviorModifier(string $hookName, string $modifier): bool
    {
        $modifierGetter = 'get' . $modifier;
        foreach ($this->getTable()->getBehaviors() as $behavior) {
            if (method_exists($behavior->$modifierGetter(), $hookName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether any registered behavior on that table has a modifier for a hook
     *
     * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
     * @param string $modifierId The name of the modifier object providing the method in the behavior
     * @param string $script The script will be modified in this method.
     * @param string $tab
     *
     * @return void
     */
    public function applyBehaviorModifierBase(string $hookName, string $modifierId, string &$script, string $tab = '        '): void
    {
        $modifierGetter = "get$modifierId";
        foreach ($this->getTable()->getBehaviors() as $behavior) {
            $modifier = $behavior->$modifierGetter();
            if (!method_exists($modifier, $hookName)) {
                continue;
            }
            if (strpos($hookName, 'Filter') !== false) {
                // filter hook: the script string will be modified by the behavior
                $modifier->$hookName($script, $this);
            } else {
                // regular hook: the behavior returns a string to append to the script string
                $addedScript = $modifier->$hookName($this);
                if (!$addedScript) {
                    continue;
                }
                $script .= "\n" . $tab . '// ' . $behavior->getId() . " behavior\n";
                $script .= preg_replace('/^/m', $tab, $addedScript);
            }
        }
    }

    /**
     * Checks whether any registered behavior content creator on that table exists a contentName
     *
     * @param string $contentName The name of the content as called from one of this class methods, e.g. "parentClassName"
     * @param string $modifier The name of the modifier object providing the method in the behavior
     *
     * @return string|null
     */
    public function getBehaviorContentBase(string $contentName, string $modifier): ?string
    {
        $modifierGetter = 'get' . $modifier;
        foreach ($this->getTable()->getBehaviors() as $behavior) {
            $modifier = $behavior->$modifierGetter();
            if (method_exists($modifier, $contentName)) {
                return $modifier->$contentName($this);
            }
        }

        return null;
    }

    /**
     * Use Propel simple templating system to render a PHP file using variables
     * passed as arguments. The template file name is relative to the behavior's
     * directory name.
     *
     * @param string $filename
     * @param array $vars
     * @param string|null $templatePath
     *
     * @throws \Propel\Generator\Exception\InvalidArgumentException
     *
     * @return string
     */
    public function renderTemplate(string $filename, array $vars = [], ?string $templatePath = null): string
    {
        if ($templatePath === null) {
            $templatePath = $this->getTemplatePath(__DIR__);
        }

        $filePath = $templatePath . $filename;
        if (!file_exists($filePath)) {
            // try with '.php' at the end
            $filePath = $filePath . '.php';
            if (!file_exists($filePath)) {
                throw new InvalidArgumentException(sprintf('Template `%s` not found in `%s` directory', $filename, $templatePath));
            }
        }
        $template = new PropelTemplate();
        $template->setTemplateFile($filePath);
        $vars = array_merge($vars, ['behavior' => $this]);

        return $template->render($vars);
    }

    /**
     * @return string
     */
    public function getTableMapClass(): string
    {
        return $this->getStubObjectBuilder()->getUnqualifiedClassName() . 'TableMap';
    }

    /**
     * Most of the code comes from the PHP-CS-Fixer project
     *
     * @param string $content
     *
     * @return string
     */
    private function clean(string $content): string
    {
        // line feed
        $content = str_replace("\r\n", "\n", $content);

        // trailing whitespaces
        $content = (string)preg_replace('/[ \t]*$/m', '', $content);

        // indentation
        $content = (string)preg_replace_callback('/^([ \t]+)/m', function ($matches) {
            return str_replace("\t", '    ', $matches[0]);
        }, $content);

        // Unused "use" statements
        preg_match_all('/^use (?P<class>[^\s;]+)(?:\s+as\s+(?P<alias>.*))?;/m', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($match['alias'])) {
                $short = $match['alias'];
            } else {
                $parts = explode('\\', $match['class']);
                $short = array_pop($parts);
            }

            preg_match_all('/\b' . $short . '\b/i', str_replace($match[0] . "\n", '', $content), $m);
            if (!count($m[0])) {
                $content = str_replace($match[0] . "\n", '', $content);
            }
        }

        // end of line
        if (strlen($content) && substr($content, -1) != "\n") {
            $content = $content . "\n";
        }

        return $content;
    }

    /**
     * Opens class.
     *
     * @param string $script
     *
     * @return void
     */
    abstract protected function addClassOpen(string &$script): void;

    /**
     * This method adds the contents of the generated class to the script.
     *
     * This method is abstract and should be overridden by the subclasses.
     *
     * Hint: Override this method in your subclass if you want to reorganize or
     * drastically change the contents of the generated object class.
     *
     * @param string $script The script will be modified in this method.
     *
     * @return void
     */
    abstract protected function addClassBody(string &$script): void;

    /**
     * Closes class.
     *
     * @param string $script
     *
     * @return void
     */
    abstract protected function addClassClose(string &$script): void;

    /**
     * Returns the vendor info from the table for the configured platform.
     *
     * @return \Propel\Generator\Model\VendorInfo
     */
    protected function getVendorInfo(): VendorInfo
    {
        $dbVendorId = $this->getPlatform()->getDatabaseType();

        return $this->getTable()->getVendorInfoForType($dbVendorId);
    }

    /**
     * @psalm-return 'true'|'false'
     *
     * @see \Propel\Generator\Model\VendorInfo::getUuidSwapFlagLiteral()
     *
     * @return string
     */
    public function getUuidSwapFlagLiteral(): string
    {
        return $this->getVendorInfo()->getUuidSwapFlagLiteral();
    }
}
