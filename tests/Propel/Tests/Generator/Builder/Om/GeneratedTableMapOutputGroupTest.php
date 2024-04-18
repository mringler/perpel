<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Om;

use Map\GeneratedTableMapOutputGroupTestTableTableMap;
use Propel\Generator\Util\QuickBuilder;
use Propel\Tests\TestCase;

class GeneratedTableMapOutputGroupTest extends TestCase
{
    /**
     * @return void
     */
    public function setUp(): void
    {
        if (class_exists('GeneratedTableMapOutputGroupTestTable')) {
            return;
        }
        $tableName = 'generated_table_map_output_group_test_table';
        $fkTableName = 'generated_table_map_output_group_test_foreign_table';
        $schema = <<<EOF
<database>
    <table name="$tableName">
        <column name="fkGroup1" type="integer" />
        <column name="fkGroup2" type="integer" />
        <column name="colGroup1" type="integer" outputGroup="group1"/>
        <column name="colGroup2" type="integer" outputGroup="group2"/>
        <column name="nogroupCol" type="integer" />
        <foreign-key foreignTable="$fkTableName" phpName="LocalFk0" outputGroup="group1">
            <reference local="fkGroup1" foreign="ft_col0"/>
        </foreign-key>
        <foreign-key foreignTable="$fkTableName" phpName="LocalFk1" outputGroup="group1,group2">
            <reference local="fkGroup2" foreign="ft_col1"/>
        </foreign-key>
    </table>
    <table name="$fkTableName">
        <column name="ft_col0" type="integer" />
        <column name="ft_col1" type="integer" />
        <column name="ft_fkGroup1" type="integer" />
        <column name="ft_fkGroup2" type="integer" />

        <foreign-key foreignTable="$tableName" phpName="RefFk2" refOutputGroup="group1">
            <reference local="ft_fkGroup1" foreign="colGroup1"/>
        </foreign-key>
        <foreign-key foreignTable="$tableName" phpName="RefFk3" refOutputGroup="group1,group2">
            <reference local="ft_fkGroup2" foreign="colGroup2"/>
        </foreign-key>
    </table>
</database>
EOF;
        QuickBuilder::buildSchema($schema);
    }

    public function groupDataProvider(): array
    {
        return [
        [
            'group1', [
                'column_index' => [2],
                'relation' => [
                    'LocalFk0' => 1,
                    'LocalFk1' => 1,
                    'GeneratedTableMapOutputGroupTestForeignTableRelatedByFtFkgroup1' => 1,
                    'GeneratedTableMapOutputGroupTestForeignTableRelatedByFtFkgroup2' => 1,
                ],
            ],
        ], [
            'group2', [
                'column_index' => [3],
                'relation' => [
                    'LocalFk1' => 1,
                    'GeneratedTableMapOutputGroupTestForeignTableRelatedByFtFkgroup2' => 1,
                ],
            ],
        ], [
            'unknown_group', [
                'column_index' => [0, 1, 2, 3, 4],
                'relation' => null,
                ],
        ]];
    }

    /**
     * @dataProvider groupDataProvider
     *
     * @return void
     */
    public function testGetOutputGroupData(string $groupName, array $expectedGroupData)
    {
        $groupData = GeneratedTableMapOutputGroupTestTableTableMap::getOutputGroupData($groupName);
        $this->assertEquals($expectedGroupData, $groupData);
    }
}
