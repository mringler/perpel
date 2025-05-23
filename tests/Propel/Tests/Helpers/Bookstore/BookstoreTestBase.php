<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Helpers\Bookstore;

use Propel\Runtime\Propel;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\TestCaseFixturesDatabase;

/**
 * Base class contains some methods shared by subclass test cases.
 */
abstract class BookstoreTestBase extends TestCaseFixturesDatabase
{
    /**
     * @var bool
     */
    protected static $isInitialized = false;

    /**
     * @var \PDO|\Propel\Runtime\Connection\ConnectionWrapper
     */
    protected $con;

    /**
     * This is run before each unit test; it populates the database.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (self::$isInitialized !== true) {
            $file = __DIR__ . '/../../../../Fixtures/bookstore/build/conf/bookstore-conf.php';
            if (!file_exists($file)) {
                return;
            }
            Propel::init($file);
            self::$isInitialized = true;
        }
        $this->con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $this->con->beginTransaction();
    }

    /**
     * This is run after each unit test. It empties the database.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Only commit if the transaction hasn't failed.
        // This is because tearDown() is also executed on a failed tests,
        // and we don't want to call ConnectionInterface::commit() in that case
        // since it will trigger an exception on its own
        // ('Cannot commit because a nested transaction was rolled back')
        if ($this->con !== null) {
            if ($this->con->isCommitable()) {
                $this->con->commit();
            } else {
               $this->con->rollback();
            }
            $this->con = null;
        }
    }

    /**
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        Propel::getServiceContainer()->closeConnections();
    }
}
