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

namespace Propel\Tests\Generator\Behavior\SyncedTable;

use Exception;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Model\Database;
use Propel\Generator\Model\Diff\TableComparator;
use Propel\Generator\Model\Diff\TableDiff;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Tests\TestCase;

/**
 */
class SyncedTableBehaviorTest extends TestCase
{
 /**
  * @return array
  */
    public function syncTestDataProvider(): array
    {
        return [
            [
                // description
                'Should sync columns',
                //additional behavior parameters
                '',
                // source table columns: some columns
                '
                <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER"/>
                <column name="fk_column" type="INTEGER"/>
                <column name="string_column" type="VARCHAR" size="42"/>
                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '',
                // synced output columns
                '
                <column name="id" required="true" primaryKey="true" type="INTEGER"/>
                <column name="fk_column" type="INTEGER"/>
                <column name="string_column" type="VARCHAR" size="42"/>
                ',
            ], [
                // description
                'Cannot override columns declared on synced table',
                //additional behavior parameters
                '',
                // source table columns: column with size 8
                '<column name="string_column" type="VARCHAR" size="8"/>',
                // synced table input columns: column with size 999
                '<column name="string_column" type="VARCHAR" size="999"/>',
                // auxiliary schema data
                '',
                // synced output columns
                '<column name="string_column" type="VARCHAR" size="999"/>',
            ], [
                // description
                'Should sync index by default',
                //additional behavior parameters
                '',
                // source table columns: column with index
                '
                <column name="string_column" type="VARCHAR" size="42"/>
                <index>
                    <index-column name="string_column" />
                </index>
                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '',
                // synced output columns
                '
                <column name="string_column" type="VARCHAR" size="42"/>
                <index name="synced_table_i_811f1f">
                    <index-column name="string_column" />
                </index>
                ',
            ], [
                // description
                'Syncing index can be disabled',
                //additional behavior parameters
                '<parameter name="sync_indexes" value="false"/>',
                // source table columns: column with index
                '
                <column name="string_column" type="VARCHAR" size="42"/>
                <index>
                    <index-column name="string_column" />
                </index>
                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '',
                // synced output columns
                '
                <column name="string_column" type="VARCHAR" size="42"/>
                ',
            ], [
                // description
                'Should sync fk column without relation',
                //additional behavior parameters
                '',
                // source table columns: column with fk
                '
                <column name="fk_column" type="INTEGER"/>
                <foreign-key foreignTable="fk_table" name="LeFk">
                    <reference local="fk_column" foreign="id"/>
                </foreign-key>
                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '
                <table name="fk_table">
                    <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER"/>
                </table>
                ',
                // synced output columns
                '<column name="fk_column" type="INTEGER"/>',
            ], [
                // description
                'Should sync fk column with relation through parameter',
                //additional behavior parameters
                '<parameter name="inherit_foreign_key_constraints" value="true"/>',
                // source table columns: column with fk
                '
                <column name="fk_column" type="INTEGER"/>
                <foreign-key foreignTable="fk_table" name="LeFk">
                    <reference local="fk_column" foreign="id"/>
                </foreign-key>
                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '
                <table name="fk_table">
                    <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER"/>
                </table>
                ',
                // synced output columns
                '
                <column name="fk_column" type="INTEGER"/>
                <foreign-key foreignTable="fk_table" name="LeFk">
                    <reference local="fk_column" foreign="id"/>
                </foreign-key>
                ',
            ], [
                // description
                'Behavior can override synced FKs',
                //additional behavior parameters: inherit fks but override relation "LeName"
                '
                <parameter name="inherit_foreign_key_constraints" value="true"/>
                <parameter-list name="foreign_keys">
                    <parameter-list-item>
                        <parameter name="name" value="LeName" />
                        <parameter name="localColumn" value="fk_column" />
                        <parameter name="foreignTable" value="new_table" />
                        <parameter name="foreignColumn" value="id" />
                    </parameter-list-item>
                </parameter-list>
                ',
                // source table columns: column with fk
                '
                <column name="fk_column" type="INTEGER"/>
                <foreign-key foreignTable="old_table" name="LeName">
                    <reference local="fk_column" foreign="id"/>
                </foreign-key>
                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '
                <table name="old_table">
                    <column name="id" type="INTEGER"/>
                </table>
                <table name="new_table">
                    <column name="id" type="INTEGER"/>
                </table>
                ',
                // synced output columns
                '
                <column name="fk_column" type="INTEGER"/>
                <foreign-key foreignTable="new_table" name="LeName">
                    <reference local="fk_column" foreign="id"/>
                </foreign-key>
                ',
            ], [
                // description
                'Behavior cannot override FKs declared on synced table',
                //additional behavior parameters: declare fk
                '
                <parameter-list name="foreign_keys">
                    <parameter-list-item>
                        <parameter name="name" value="LeName" />
                        <parameter name="localColumn" value="fk_column" />
                        <parameter name="foreignTable" value="new_table" />
                        <parameter name="foreignColumn" value="id" />
                    </parameter-list-item>
                </parameter-list>
                ',
                // source table columns
                '<column name="fk_column" type="INTEGER"/>',
                // synced table input columns: fk conflicting with behavior fk
                '
                <column name="fk_column" type="INTEGER"/>
                <foreign-key foreignTable="old_table" name="LeName">
                    <reference local="fk_column" foreign="id"/>
                </foreign-key>
                ',
                // auxiliary schema data
                '
                <table name="old_table">
                    <column name="id" type="INTEGER"/>
                </table>
                <table name="new_table">
                    <column name="id" type="INTEGER"/>
                </table>
                ',
                // synced output columns: expect exception
                EngineException::class,
            ], [
                // description
                'Behavior does not sync unique indexes by default',
                //additional behavior parameters
                '',
                // source table columns: column with fk
                '
                <column name="col1" type="INTEGER" />
                <column name="col2" type="INTEGER" />
                <column name="col3" type="INTEGER" />
                <unique>
                    <unique-column name="col1" />
                </unique>
                <unique>
                    <unique-column name="col2" />
                    <unique-column name="col3" />
                </unique>

                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '',
                // synced output columns
                '
                <column name="col1" type="INTEGER" />
                <column name="col2" type="INTEGER" />
                <column name="col3" type="INTEGER" />
                ',
            ], [
                // description
                'Behavior syncs unique indexes as regular indexes if requested',
                //additional behavior parameters
                '<parameter name="sync_unique_as" value="index"/>',
                // source table columns: column with fk
                '
                <column name="col1" type="INTEGER" />
                <column name="col2" type="INTEGER" />
                <column name="col3" type="INTEGER" />
                <unique>
                    <unique-column name="col1" />
                </unique>
                <unique>
                    <unique-column name="col2" />
                    <unique-column name="col3" />
                </unique>

                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '',
                // synced output columns
                '
                <column name="col1" type="INTEGER" />
                <column name="col2" type="INTEGER" />
                <column name="col3" type="INTEGER" />
                <index>
                    <index-column name="col1" />
                </index>
                <index>
                    <index-column name="col2" />
                    <index-column name="col3" />
                </index>
                ',
            ], [
                // description
                'Behavior syncs unique indexes as unique indexes if requested',
                //additional behavior parameters
                '<parameter name="sync_unique_as" value="unique"/>',
                // source table columns: column with fk
                '
                <column name="col1" type="INTEGER" />
                <column name="col2" type="INTEGER" />
                <column name="col3" type="INTEGER" />
                <unique>
                    <unique-column name="col1" />
                </unique>
                <unique>
                    <unique-column name="col2" />
                    <unique-column name="col3" />
                </unique>

                ',
                // synced table input columns
                '',
                // auxiliary schema data
                '',
                // synced output columns
                '
                <column name="col1" type="INTEGER" />
                <column name="col2" type="INTEGER" />
                <column name="col3" type="INTEGER" />
                <unique>
                    <unique-column name="col1" />
                </unique>
                <unique>
                    <unique-column name="col2" />
                    <unique-column name="col3" />
                </unique>
                ',
            ],
        ];
    }

    /**
     * @dataProvider syncTestDataProvider
     *
     * @param string $message
     * @param string $behaviorAdditions
     * @param string $sourceTableContentTags
     * @param string $syncedTableInputTags
     * @param string $auxiliaryTables
     * @param string $syncedTableOutputTags
     *
     * @return void
     */
    public function testSync(
        string $message,
        string $behaviorAdditions,
        string $sourceTableContentTags,
        string $syncedTableInputTags,
        string $auxiliaryTables,
        string $syncedTableOutputTags
    ) {
        // source table: some columns
        // synced table: empty
        $inputSchemaXml = <<<EOT
<database>
    <table name="source_table">
        <behavior name="synced_table">
            <parameter name="synced_table" value="synced_table"/>
            <parameter name="sync" value="true"/>
            $behaviorAdditions
        </behavior>

        $sourceTableContentTags

    </table>
    
    $auxiliaryTables

    <table name="synced_table">$syncedTableInputTags</table>
</database>
EOT;

        // synced table: all columns
        $expectedTableXml = <<<EOT
<database>
    <table name="synced_table">
    $syncedTableOutputTags
    </table>

    $auxiliaryTables
</database>
EOT;

        if (class_exists($syncedTableOutputTags) && is_subclass_of($syncedTableOutputTags, Exception::class)) {
            $this->expectException($syncedTableOutputTags);
        }
        $this->assertSchemaTableMatches($expectedTableXml, $inputSchemaXml, 'synced_table', $message);
    }

    /**
     * @param string $expectedTableXml
     * @param string $schema
     * @param string $tableName
     * @param string|null $message
     *
     * @return void
     */
    protected function assertSchemaTableMatches(string $expectedTableXml, string $inputSchemaXml, string $tableName, ?string $message = null)
    {
        $expectedSchema = $this->buildSchema($expectedTableXml);
        $expectedTable = $expectedSchema->getTable($tableName);

        $actualSchema = $this->buildSchema($inputSchemaXml);
        $actualTable = $actualSchema->getTable($tableName);

        $diff = TableComparator::computeDiff($actualTable, $expectedTable);
        if ($diff !== false) {
            $message = $this->buildTestMessage($message, $diff, $expectedSchema, $actualSchema);
            $this->fail($message);
        }
        $this->expectNotToPerformAssertions();
    }

    /**
     * @param string $schema
     *
     * @return \Propel\Generator\Model\Database
     */
    protected function buildSchema(string $schema): Database
    {
        $builder = new QuickBuilder();
        $builder->setSchema($schema);

        return $builder->getDatabase();
    }

    /**
     * @param string $inputMessage
     * @param \Propel\Generator\Model\Diff\TableDiff $diff
     * @param \Propel\Generator\Model\Database $expectedSchema
     * @param \Propel\Generator\Model\Database $actualSchema
     *
     * @return string
     */
    protected function buildTestMessage(string $inputMessage, TableDiff $diff, Database $expectedSchema, Database $actualSchema)
    {
        $inputMessage ??= '';
        $platform = new MysqlPlatform();
        $sql = $platform->getModifyTableDDL($diff);

        return <<<EOT
$inputMessage

Synced table not as expected:
───────────────────────────────────────────────────────
Diff summary:

$diff

───────────────────────────────────────────────────────
DDL (MySQL) to turn actual table into expected table:

$sql

───────────────────────────────────────────────────────
Expected database:

$expectedSchema

───────────────────────────────────────────────────────
Actual database:

$actualSchema
───────────────────────────────────────────────────────

EOT;
    }
}
