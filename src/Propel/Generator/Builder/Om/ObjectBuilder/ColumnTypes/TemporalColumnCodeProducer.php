<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

use DateTime;
use Exception;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Platform\MysqlPlatform;

class TemporalColumnCodeProducer extends ColumnCodeProducer
{
    /**
     * @return string
     */
    #[\Override]
    protected function getQualifiedTypeString(): string
    {
        return $this->resolveColumnDateTimeClass($this->column);
    }

    /**
     * Returns the type-casted and stringified default value for the specified
     * Column. This only works for scalar default values currently.
     *
     * @throws \Propel\Generator\Exception\EngineException
     *
     * @return string
     */
    #[\Override]
    public function getDefaultValueString(): string
    {
        $defaultValue = 'null';
        $val = $this->column->getPhpDefaultValue();
        if ($val === null) {
            return $defaultValue;
        }

        $fmt = $this->objectBuilder->getTemporalFormatter($this->column);
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

            throw new EngineException(sprintf('Unable to parse default temporal value "%s" for column "%s"', $this->column->getDefaultValueString(), $this->column->getFullyQualifiedName()), 0, $exception);
        }

        return $defaultValue;
    }

    /**
     * Adds the comment for a temporal accessor.
     *
     * @param string $script
     * @param string $additionalParam injected from outer class (lazy load)
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorComment(string &$script, string $additionalParam = ''): void
    {
        $column = $this->column;
        $clo = $column->getLowercasedName();

        $dateTimeClass = $this->resolveColumnDateTimeClass($this->column);

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
     * Get the [optionally formatted] temporal [$clo] column value.{$this->getColumnDescriptionDoc()}
     *
     * @psalm-return (\$format is null ? {$dateTimeClass}{$orNull} : string{$orNull})
     *
     * @param string|null \$format The date/time format string (either date()-style or strftime()-style).
     *   If format is NULL, then the raw $dateTimeClass object will be returned.{$additionalParam}
     *
     * @return {$dateTimeClass}|string{$orNull} Formatted date/time value as string or $dateTimeClass object (if format is NULL){$descriptionReturnValueNull}{$descriptionReturnMysqlInvalidDate}.
     */";
    }

    /**
     * Gets the default format for a temporal column from the configuration
     *
     * @return string|null
     */
    protected function getTemporalTypeDefaultFormat(): ?string
    {
        $configKey = $this->getTemporalTypeDefaultFormatConfigKey();

        return $configKey ? $this->getBuildProperty($configKey) : null;
    }

    /**
     * Knows which key in the configuration holds the default format for a
     * temporal type column.
     *
     * @return string|null
     */
    protected function getTemporalTypeDefaultFormatConfigKey(): ?string
    {
        return match ($this->column->getType()) {
            PropelTypes::DATE => 'generator.dateTime.defaultDateFormat',
            PropelTypes::TIME => 'generator.dateTime.defaultTimeFormat',
            PropelTypes::TIMESTAMP, PropelTypes::DATETIME => 'generator.dateTime.defaultTimeStampFormat',
            default => null,
        };
    }

    /**
     * Adds the function declaration for a temporal accessor.
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

        $defaultfmt = $this->getTemporalTypeDefaultFormat();
        $visibility = $this->column->getAccessorVisibility();

        $format = $defaultfmt === null ? 'null' : var_export($defaultfmt, true);

        $maybeCon = $additionalParam ? ", $additionalParam" : '';

        $script .= "
    $visibility function get$cfc(\$format = {$format}{$maybeCon})
    {";
    }

    /**
     * Adds the body of the temporal accessor.
     *
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    protected function addAccessorBody(string &$script): void
    {
        $this->declareClass('DateTimeInterface');
        $clo = $this->column->getLowercasedName();

        $script .= "
        if (\$format === null) {
            return \$this->$clo;
        } else {
            return \$this->$clo instanceof DateTimeInterface ? \$this->{$clo}->format(\$format) : null;
        }";
    }

    /**
     * @param string $script
     *
     * @return void
     */
    #[\Override]
    public function addMutatorComment(string &$script): void
    {
        $col = $this->column;
        $clo = $col->getLowercasedName();
        $orNull = $col->isNotNull() ? '' : '|null';

        $script .= "
    /**
     * Sets the value of [$clo] column to a normalized version of the date/time value specified.{$this->getColumnDescriptionDoc()}
     *
     * @param \DateTimeInterface|string|int{$orNull} \$v string, integer (timestamp), or \DateTimeInterface value.
     *               Empty strings are treated as NULL.
     *
     * @return \$this
     */";
    }

    /**
     * Adds a setter method for date/time/timestamp columns.
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

        $dateTimeClass = $this->resolveColumnDateTimeClass($this->column);
        $this->declareClasses('\Propel\Runtime\Util\PropelDateTime');

        $fmt = var_export($this->objectBuilder->getTemporalFormatter($col), true);

        $script .= "
        \$dt = PropelDateTime::newInstance(\$v, null, '$dateTimeClass');
        if (\$this->$clo !== null || \$dt !== null) {";

        $def = $col->getDefaultValue();
        if ($def !== null && !$def->isExpression()) {
            $defaultValue = $this->getDefaultValueString();
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
                \$this->modifiedColumns[" . $this->objectBuilder->getColumnConstant($col) . "] = true;
            }
        } // if either are not null
";
    }
}
