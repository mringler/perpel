<?php

/*
 *	$Id$
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT License
 */

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Behavior\Auditable;

use Propel\Generator\Behavior\SyncedTable\SyncedTableException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\PropelTypes;
use Propel\Generator\Util\QuickBuilder;
use Propel\Tests\TestCase;

/**
 */
class AuditableBehaviorDeclarationTest extends TestCase
{
    /**
     * @return void
     */
    public function testTableDefaults(): void
    {
        $schemaXml = <<<EOT
        <database>
            <table name="source_table">
                <column name="int_col" type="INTEGER" primaryKey="true"/>

                <behavior name="auditable"></behavior>
            </table>
        </database>
EOT;
        $builder = new QuickBuilder();
        $builder->setSchema($schemaXml);

        $database = $builder->getDatabase();
        $table = $database->getTable('source_table_audit');
        $this->assertNotNull($table, 'Audit table should have been created with expected name');
        $fieldNames = array_map(fn(Column $col) => $col->getName(), $table->getColumns());
        $expectedFieldNames = ['source_table_int_col', 'audit_id', 'audited_at', 'audit_event', 'internal_changed_values'];
        $this->assertEquals($expectedFieldNames, $fieldNames);
    }

    /**
     * @return void
     */
    public function testTableParameters(): void
    {
        $schemaXml = <<<EOT
           <database>
               <table name="source_table">
                   <column name="int_col" type="INTEGER" primaryKey="true"/>
   
                   <behavior name="auditable">
                        <parameter name="table_name" value="le_source_table_audit"/>
                        <parameter name="id_field_name" value="le_id"/>
                        <parameter name="audited_at_field_name" value="le_at"/>
                        <parameter name="changed_values_field_name" value="le_changes"/>
                        <parameter name="changed_values_field_type" value="CHAR"/>
                        <parameter name="changed_values_field_size" value="2"/>
                        <parameter name="column_prefix" value="source_"/>
                        <parameter-list name="relation">
                            <parameter-list-item>
                                <parameter name="le_attribute" value="le_value"/>
                            </parameter-list-item>
                        </parameter-list>
                   </behavior>
               </table>
           </database>
   EOT;
        $builder = new QuickBuilder();
        $builder->setSchema($schemaXml);

        $database = $builder->getDatabase();
        $table = $database->getTable('le_source_table_audit');
        $this->assertNotNull($table, 'Audit table should have been created with expected name');

        $fieldNames = array_map(fn(Column $col) => $col->getName(), $table->getColumns());
        $expectedFieldNames = ['source_int_col', 'le_id', 'le_at', 'audit_event', 'internal_le_changes'];
        $this->assertEquals($expectedFieldNames, $fieldNames);

        $changedValuesDomain = $table->getColumn('internal_le_changes')->getDomain();
        $this->assertEquals(PropelTypes::CHAR, $changedValuesDomain->getType());
        $this->assertEquals(2, $changedValuesDomain->getSize());

        $relation = $table->getForeignKeys()[0];
        $this->assertNotNull($relation);
        $this->assertEquals('le_value', $relation->getAttribute('le_attribute'));
    }

    /**
     * @return void
     */
    public function testSelectAuditedColumns(): void
    {
        $schemaXml = <<<EOT
        <database>
            <table name="table">
                <column name="int_col" type="INTEGER" />
                <column name="password" type="VARCHAR" />
                <column name="blob_col" type="BLOB" />
                <column name="changed_at" type="TIMESTAMP" />

                <behavior name="auditable">
                    <parameter name="ignore_fields" value="changed_at"/>
                    <parameter name="omit_value_fields" value="password"/>
                    <parameter name="omit_value_types" value="BLOB"/>
                    <parameter name="omit_value" value="was changed"/>
                </behavior>
            </table>
        </database>
EOT;
        $builder = new QuickBuilder();
        $builder->setSchema($schemaXml);

        $database = $builder->getDatabase();
        $table = $database->getTable('table');
        $behavior = $table->getBehavior('auditable');

        $selected = $this->callMethod($behavior, 'selectAuditedFields');

        $this->assertIsArray($selected);

        $expected = [
            ['column' => $table->getColumn('int_col'), 'isOmitted' => false],
            ['column' => $table->getColumn('password'), 'isOmitted' => true],
            ['column' => $table->getColumn('blob_col'), 'isOmitted' => true],
        ];

        $this->assertEqualsCanonicalizing($expected, $selected);
    }


    /**
     * @return void
     */
    public function testCannotCombineCascadeParamterWithRelationArray(): void
    {
        $schemaXml = <<<EOT
        <database>
            <table name="table">
                <column name="int_col" type="INTEGER" />
                <behavior name="auditable">
                    <parameter name="cascade_delete" value="true"/>
                    <parameter-list name="relation">
                        <parameter-list-item/>
                    </parameter-list>
                </behavior>
            </table>
        </database>
EOT;
        $builder = new QuickBuilder();
        $builder->setSchema($schemaXml);

        $this->expectException(SyncedTableException::class);
        $this->expectExceptionMessage("Cannot combine parameter 'cascade_delete' with array input for relation ('relation') - set onDelete behavior in array.");
        $builder->getDatabase();

    }
}
