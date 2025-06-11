<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery;

use Exception;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\FilterExpression\CombineOperatorManager;
use Propel\Tests\TestCase;

/**
 */
class CombineOperatorManagerTest extends TestCase
{
    /**
     * @return void
     */
    public function testDefaultOperator(): void
    {
        $defaultOp = (new CombineOperatorManager())->getOperator();

        $this->assertSame(Criteria::LOGICAL_AND, $defaultOp);
    }

    /**
     * @return void
     */
    public function testResetOperatorBeyondStack(): void
    {
        $manager = new CombineOperatorManager();
        try {
            $manager->resetOperator();
            $manager->resetOperator();
        } catch (Exception $e) {
            $this->fail('resetting operator on empty stack should reset to AND');
        }

        $nextOp = $manager->getOperator();
        $this->assertSame(Criteria::LOGICAL_AND, $nextOp);
    }

    /**
     * @return void
     */
    public function testOneTimeOperator(): void
    {
        $manager = new CombineOperatorManager();
        $manager->setOperator('XOR', true);

        $this->assertSame('XOR', $manager->getOperator());
        $this->assertSame(Criteria::LOGICAL_AND, $manager->getOperator());
    }

    /**
     * @return void
     */
    public function testFixedOperator(): void
    {
        $manager = new CombineOperatorManager();
        $manager->setOperator('XOR', false);

        $this->assertSame('XOR', $manager->getOperator());
        $this->assertSame('XOR', $manager->getOperator());
        $manager->resetOperator();
        $this->assertSame(Criteria::LOGICAL_AND, $manager->getOperator());
    }

    /**
     * @return void
     */
    public function testStackedFixedOperator(): void
    {
        $manager = new CombineOperatorManager();
        $manager->setOperator('NAND', false);
        $manager->setOperator('XOR', false);

        $this->assertSame('XOR', $manager->getOperator());
        $this->assertSame('XOR', $manager->getOperator());
        $manager->resetOperator();
        $this->assertSame('NAND', $manager->getOperator());
        $this->assertSame('NAND', $manager->getOperator());
        $manager->resetOperator();
        $this->assertSame(Criteria::LOGICAL_AND, $manager->getOperator());
    }

    /**
     * @return void
     */
    public function testOneTimeOperatorOnFixedOperator(): void
    {
        $manager = new CombineOperatorManager();
        $manager->setOperator('XOR', false);
        $manager->setOperator('NAND', true);

        $this->assertSame('NAND', $manager->getOperator());
        $this->assertSame('XOR', $manager->getOperator());
        $this->assertSame('XOR', $manager->getOperator());
        $manager->resetOperator();
        $this->assertSame(Criteria::LOGICAL_AND, $manager->getOperator());
    }

    /**
     * @return void
     */
    public function testFixingOperatorOverridesOneTimeOperator(): void
    {
        $manager = new CombineOperatorManager();
        $manager->setOperator('XOR', true);
        $manager->setOperator('NAND', false);

        $this->assertSame('NAND', $manager->getOperator());
        $this->assertSame('NAND', $manager->getOperator());
        $manager->resetOperator();
        $this->assertSame(Criteria::LOGICAL_AND, $manager->getOperator());
    }

    /**
     * @return void
     */
    public function testGetLastPermanentOperator(): void
    {
        $manager = new CombineOperatorManager();
        $manager->setOperator('My permanent operator', false);
        $manager->setOperator('My one-time Operator', true);

        $this->assertSame('My permanent operator', $manager->getCurrentPermanentOperator(), 'should ignore one-time operator');
        $this->assertSame('My one-time Operator', $manager->getOperator(), 'should not interfere with one-time operator');
        $this->assertSame('My permanent operator', $manager->getOperator(), 'should return to permanent operator');
    }
}
