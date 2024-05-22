<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\Auditable;

use Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior;
use Propel\Generator\Behavior\SyncedTable\SyncedTableBehaviorDeclaration;
use Propel\Generator\Behavior\SyncedTable\SyncedTableException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\PropelTypes;

class AuditableBehaviorDeclaration extends SyncedTableBehavior
{
    /**
     * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior::DEFAULT_SYNCED_TABLE_SUFFIX
     *
     * @var string DEFAULT_SYNCED_TABLE_SUFFIX
     */
    protected const DEFAULT_SYNCED_TABLE_SUFFIX = '_audit';

    /**
     * @var string
     */
    public const PARAMETER_KEY_ADD_PK = 'id_field_name';

    /**
     * @var string
     */
    public const PARAMETER_KEY_AUDITED_AT_FIELD_NAME = 'audited_at_field_name';

    /**
     * @var string
     */
    public const PARAMETER_KEY_AUDIT_EVENT_FIELD_NAME = 'audit_event_field_name';

    /**
     * @var string
     */
    public const PARAMETER_KEY_CHANGED_VALUES_FIELD_NAME = 'changed_values_field_name';

    /**
     * @var string
     */
    public const PARAMETER_KEY_CHANGED_VALUES_FIELD_TYPE = 'changed_values_field_type';

    /**
     * @var string
     */
    public const PARAMETER_KEY_CHANGED_VALUES_FIELD_SIZE = 'changed_values_field_size';

    /**
     * @var string
     */
    public const PARAMETER_KEY_IGNORE_FIELDS = 'ignore_fields';

    /**
     * @var string
     */
    public const PARAMETER_KEY_OMIT_VALUE_FIELDS = 'omit_value_fields';

    /**
     * @var string
     */
    public const PARAMETER_KEY_OMIT_VALUE_TYPES = 'omit_value_types';

    /**
     * Placeholder for omited column values (defaults to 'changed')
     *
     * @var string
     */
    public const PARAMETER_KEY_OMIT_VALUE = 'omit_value';

    /**
     * By default, the insert audit is empty, this parameter allows to specify
     * columns that should be added.
     *
     * @var string
     */
    public const PARAMETER_KEY_AUDITED_COLUMNS_ON_INSERT = 'audited_columns_on_insert';

    /**
     * Remove audit when source row is deleted (defaults to false).
     *
     * @var string
     */
    public const PARAMETER_KEY_CASCADE_DELETE = 'cascade_delete';

    /**
     * Name of the aggregation column or "true" for "number_of_audits".
     *
     * @var string
     */
    public const PARAMETER_KEY_ADD_AGGREGATION_TO_SOURCE = 'add_aggregation_to_source';

    /**
     * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehavior::getDefaultParameters()
     *
     * @return array
     */
    protected function getDefaultParameters(): array
    {
        // set SyncedTableBehavior values
        return [
            static::PARAMETER_KEY_ADD_PK => 'audit_id',
            static::PARAMETER_KEY_SYNC_PK_ONLY => 'true',
            static::PARAMETER_KEY_COLUMN_PREFIX => 'true',
        ];
    }

    /**
     * @throws \Propel\Generator\Behavior\SyncedTable\SyncedTableException
     *
     * @return void
     */
    public function validateParameters(): void
    {
        $disallowedParameters = [
            SyncedTableBehaviorDeclaration::PARAMETER_KEY_EMPTY_ACCESSOR_COLUMNS,
            SyncedTableBehaviorDeclaration::PARAMETER_KEY_IGNORE_COLUMNS,
            SyncedTableBehaviorDeclaration::PARAMETER_KEY_INHERIT_FOREIGN_KEY_CONSTRAINTS,
            SyncedTableBehaviorDeclaration::PARAMETER_KEY_INHERIT_FOREIGN_KEY_RELATIONS,
            SyncedTableBehaviorDeclaration::PARAMETER_KEY_SYNC_INDEXES,
            SyncedTableBehaviorDeclaration::PARAMETER_KEY_SYNC_UNIQUE_AS,
        ];

        foreach ($disallowedParameters as $disallowedParameter) {
            if (array_key_exists($disallowedParameter, $this->parameters)) {
                throw new SyncedTableException($this, "Use of parameter '$disallowedParameter' is not allowed.");
            }
        }

        parent::validateParameters();
        $this->checkColumnsInParameterExistInTable(static::PARAMETER_KEY_IGNORE_FIELDS, true);
        $this->checkColumnsInParameterExistInTable(static::PARAMETER_KEY_OMIT_VALUE_FIELDS, true);
        $this->checkColumnsInParameterExistInTable(static::PARAMETER_KEY_AUDITED_COLUMNS_ON_INSERT, true);
        if ($this->isCascadeDelete() && is_array(parent::getRelationAttributes())) {
            $format = "Cannot combine parameter '%s' with array input for relation ('%s') - set onDelete behavior in array.";
            $msg = sprintf($format, static::PARAMETER_KEY_CASCADE_DELETE, static::PARAMETER_KEY_RELATION);

            throw new SyncedTableException($this, $msg);
        }
    }

    /**
     * @return string
     */
    public function getAuditedAtFieldName(): string
    {
        return $this->getParameter(static::PARAMETER_KEY_AUDITED_AT_FIELD_NAME, 'audited_at');
    }

    /**
     * @return string
     */
    public function getAuditEventFieldName(): string
    {
        return $this->getParameter(static::PARAMETER_KEY_AUDIT_EVENT_FIELD_NAME, 'audit_event');
    }

    /**
     * @return string
     */
    public function getChangedValuesFieldName(): string
    {
        return $this->getParameter(static::PARAMETER_KEY_CHANGED_VALUES_FIELD_NAME, 'changed_values');
    }

    /**
     * @return string
     */
    public function getChangedValuesFieldPhpName(): string
    {
        return Column::generatePhpName($this->getChangedValuesFieldName());
    }

    /**
     * @return string
     */
    public function getInternalChangedValuesFieldName(): string
    {
        return 'internal_' . $this->getChangedValuesFieldName();
    }

    /**
     * @return string
     */
    public function getChangedValuesFieldType(): string
    {
        return $this->getParameter(static::PARAMETER_KEY_CHANGED_VALUES_FIELD_TYPE, PropelTypes::JSON);
    }

    /**
     * @return int|null
     */
    public function getChangedValuesFieldSize(): ?int
    {
        return $this->getParameterInt(static::PARAMETER_KEY_CHANGED_VALUES_FIELD_SIZE);
    }

    /**
     * @return array<string>
     */
    public function getIgnoredFieldNames(): array
    {
        $val = $this->getParameterCsv(static::PARAMETER_KEY_IGNORE_FIELDS);
        if ($this->aggregationColumnNameOnSource()) {
            $val[] = $this->aggregationColumnNameOnSource();
        }

        return $val;
    }

    /**
     * @return array<string>
     */
    public function getOmitValueFields(): array
    {
        return $this->getParameterCsv(static::PARAMETER_KEY_OMIT_VALUE_FIELDS);
    }

    /**
     * @return array<string>
     */
    public function getOmitValueFieldTypes(): array
    {
        return $this->getParameterCsv(static::PARAMETER_KEY_OMIT_VALUE_TYPES, ['BLOB, CLOB']);
    }

    /**
     * @return string
     */
    public function getOmitValue(): string
    {
        return $this->getParameter(static::PARAMETER_KEY_OMIT_VALUE, 'changed');
    }

    /**
     * @return array<string>
     */
    public function getAuditedColumnsOnInsert(): array
    {
        return $this->getParameterCsv(static::PARAMETER_KEY_AUDITED_COLUMNS_ON_INSERT);
    }

    /**
     * @see \Propel\Generator\Behavior\SyncedTable\SyncedTableBehaviorDeclaration::getRelationAttributes()
     *
     * @return array|null
     */
    public function getRelationAttributes(): ?array
    {
        $parentRelation = parent::getRelationAttributes();
        if (is_array($parentRelation)) {
            return $parentRelation;
        }

        return $this->isCascadeDelete()
            ? ['onDelete' => 'cascade']
            : ['skipSql' => 'true'];
    }

    /**
     * @return bool
     */
    protected function isCascadeDelete(): bool
    {
        return $this->getParameterBool(static::PARAMETER_KEY_CASCADE_DELETE, false);
    }

    /**
     * @return bool
     */
    public function relationCascadesDelete(): bool
    {
        $attributes = $this->getRelationAttributes();

        return $attributes && !empty($attributes['onDelete']) && $attributes['onDelete'] === 'cascade';
    }

    /**
     * @return string|null
     */
    public function aggregationColumnNameOnSource(): ?string
    {
        $val = $this->getParameter(static::PARAMETER_KEY_ADD_AGGREGATION_TO_SOURCE);
        if (!$val) {
            return null;
        }

        return in_array(strtolower($val), ['true', '1']) ? 'number_of_audits' : $val;
    }
}
