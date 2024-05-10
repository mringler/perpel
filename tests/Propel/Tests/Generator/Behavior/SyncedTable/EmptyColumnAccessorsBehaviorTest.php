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

use Propel\Generator\Behavior\SyncedTable\EmptyColumnAccessorsBehavior;
use Propel\Tests\TestCase;

/**
 */
class EmptyColumnAccessorsBehaviorTest extends TestCase
{
    /**
     * @return void
     */
    public function testBuildAccessorNames()
    {
        $behavior = new EmptyColumnAccessorsBehavior();
        $behavior->setParameters([
            EmptyColumnAccessorsBehavior::PARAMETER_KEY_COLUMNS => 'a_column, le_column '
        ]);
        $accessors = $this->callMethod($behavior, 'buildAccessorNames');
        $expected = ['getAColumn', 'setAColumn', 'getLeColumn', 'setLeColumn'];

        $this->assertEqualsCanonicalizing($expected, $accessors);
    }
}
