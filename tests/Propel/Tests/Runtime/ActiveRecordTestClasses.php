<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveRecord;

use Propel\Tests\Bookstore\Book;

class TestableActiveRecord extends Book
{
    public array $virtualColumns = [];
}
