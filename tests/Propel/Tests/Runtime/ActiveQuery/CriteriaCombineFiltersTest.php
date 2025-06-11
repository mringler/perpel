<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Propel\Tests\Helpers\BaseTestCase;

/**
 *
 */
class CriteriaCombineFiltersTest extends BaseTestCase
{
    /**
     * @return array<array{string, Criteria, string}>>
     */
    public function CombineFiltersDataProvider(): array
    {
        Propel::getServiceContainer()->initDatabaseMaps([]);
        return [
            [
                'no combine',
                (new Criteria())
                    ->addUsingOperator('A', 1),
                'A=1'
            ], [
                'regular AND',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->addAND('B', 2),
                'A=1 AND B=2'
            ], [
                'regular OR',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->addOr('B', 2),
                '(A=1 OR B=2)'
            ], [
                'AND with OR',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->addAnd('B', 2)
                    ->addOr('C', 3),
                'A=1 AND (B=2 OR C=3)'
            ], 
            [
                'empty combine',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                    ->endCombineFilters()
                    ->addUsingOperator('B', 2),
                'A=1 AND B=2'
            ], 
            [
                'combine one',
                (new Criteria())
                    ->combineFilters()
                    ->addUsingOperator('A', 1)
                    ->endCombineFilters(),
                'A=1'
            ], [
                'default combine with AND',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters('AND')
                    ->addUsingOperator('B', 2)
                    ->endCombineFilters(),
                'A=1 AND B=2'
            ],[
                'combine with AND',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->_and()
                    ->combineFilters()
                    ->addUsingOperator('B', 2)
                    ->endCombineFilters(),
                'A=1 AND B=2'
            ],[
                'combine with OR',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->_or()
                    ->combineFilters()
                    ->addUsingOperator('B', 2)
                    ->endCombineFilters(),
                '(A=1 OR B=2)'
            ],[
                'combine with _or()',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->_or()
                    ->combineFilters()
                    ->addUsingOperator('B', 2)
                    ->endCombineFilters(),
                '(A=1 OR B=2)'
            ],[
                'ignores first combine andOr',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                    ->addOr('B', 2)
                    ->endCombineFilters(),
                'A=1 AND B=2'
            ], [
                'combine two',
                (new Criteria())
                    ->combineFilters()
                    ->addUsingOperator('A', 1)
                    ->addAND('B', 2)
                    ->endCombineFilters(),
                '(A=1 AND B=2)'
            ], [
                'AND combined',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                    ->addUsingOperator('B', 2)
                    ->addOr('C', 3)
                    ->endCombineFilters(),
                'A=1 AND (B=2 OR C=3)'
            ], [
                'missing endCombineFilters',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                    ->addUsingOperator('B', 2)
                    ->addOr('C', 3),
                'A=1 AND ((B=2 OR C=3) ... )'
            ], [
                'combine twice with AND',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                        ->addUsingOperator('B', 2)
                        ->addOr('C', 3)
                    ->endCombineFilters()
                    ->combineFilters()
                        ->addUsingOperator('D', 4)
                        ->addOr('E', 5)
                    ->endCombineFilters(),
                'A=1 AND (B=2 OR C=3) AND (D=4 OR E=5)'
            ], [
                'combine twice with OR',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                        ->addUsingOperator('B', 2)
                        ->addOr('C', 3)
                    ->endCombineFilters()
                    ->_or()
                    ->combineFilters()
                        ->addUsingOperator('D', 4)
                        ->addAnd('E', 5)
                    ->endCombineFilters(),
                'A=1 AND ((B=2 OR C=3) OR (D=4 AND E=5))'
            ], [
                'nested combine',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters()
                        ->addUsingOperator('B', 2)
                        ->_or()
                        ->combineFilters()
                            ->addUsingOperator('D', 4)
                            ->addAnd('E', 5)
                        ->endCombineFilters()
                    ->endCombineFilters(),
                'A=1 AND (B=2 OR (D=4 AND E=5))'
            ], [
                'double nested combine',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->_or()
                    ->combineFilters()
                        ->combineFilters()
                            ->addUsingOperator('B', 2)
                            ->addOr('C', 3)
                        ->endCombineFilters()
                        ->combineFilters()
                            ->addUsingOperator('D', 4)
                            ->addOr('E', 5)
                        ->endCombineFilters()
                    ->endCombineFilters(),
                '(A=1 OR ((B=2 OR C=3) AND (D=4 OR E=5)))'
            ], [
                'triple nested combine',
                (new Criteria())
                    ->addUsingOperator('A', 1)
                    ->combineFilters('OR')
                        ->addUsingOperator('B', 2)
                        ->combineFilters('AND')
                            ->addUsingOperator('C', 3)
                            ->combineFilters('OR')
                                ->addUsingOperator('D', 4)
                                ->addAnd('E', 5)
                                ->addUsingOperator('F', 6)
                                ->_and()
                                ->addUsingOperator('G', 7)
                            ->endCombineFilters()
                        ->endCombineFilters()
                    ->endCombineFilters()
                    ->addUsingOperator('Z', 99),
                'A=1 AND (B=2 OR (C=3 AND ((D=4 AND (E=5 OR F=6)) AND G=7))) AND Z=99'
            ]
        ];
    }

    /**
     * @dataProvider CombineFiltersDataProvider
     *
     * @return void
     */
    public function testCombineFilters(string $description, Criteria $c, $expectedCondition): void
    {
        $condition = $c->getFilterCollector()->__toString();
        $this->assertEquals($expectedCondition, $condition, $description);
    }
}
