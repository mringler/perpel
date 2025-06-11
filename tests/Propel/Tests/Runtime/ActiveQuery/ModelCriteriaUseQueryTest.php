<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\ActiveQuery;

use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Propel;
use Propel\Tests\Bookstore\AuthorQuery;
use Propel\Tests\Bookstore\BookQuery;
use Propel\Tests\Bookstore\Map\BookTableMap;
use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;

/**
 * Test class for ModelCriteria.
 *
 * @author Francois Zaninotto
 *
 * @group database
 */
class ModelCriteriaUseQueryTest extends BookstoreTestBase
{
    /**
     * @return void
     */
    public function testUseQuery()
    {
        $c = new ModelCriteria('bookstore', 'Propel\Tests\Bookstore\Book', 'b');
        $c->where('b.Title = ?', 'foo');
        $c->setOffset(10);
        $c->leftJoin('b.Author');

        $c2 = $c->useQuery('Author');
        $this->assertTrue($c2 instanceof AuthorQuery, 'useQuery() returns a secondary Criteria');
        $this->assertEquals($c, $c2->getPrimaryCriteria(), 'useQuery() sets the primary Criteria os the secondary Criteria');
        $c2->where('Author.FirstName = ?', 'john');
        $c2->limit(5);

        $ret = $c2->endUse();
        
        $this->assertSame($ret, $c, 'endUse() returns the Primary Criteria');
        $this->assertEquals('Propel\Tests\Bookstore\Book', $c->getModelName(), 'endUse() returns the Primary Criteria');
        $c = $ret;
    
        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->find($con);

        if (!$this->runningOnMySQL()) {
            $expectedSQL = $this->getSql("SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM book LEFT JOIN author ON (book.author_id=author.id) WHERE book.title = 'foo' AND author.first_name = 'john' LIMIT 5 OFFSET 10");
        } else {
            $expectedSQL = $this->getSql("SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM book LEFT JOIN author ON (book.author_id=author.id) WHERE book.title = 'foo' AND author.first_name = 'john' LIMIT 10, 5");
        }

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'useQuery() and endUse() allow to merge a secondary criteria');
    }

    /**
     * @return void
     */
    public function testUseQueryAlias()
    {
        $c = new ModelCriteria('bookstore', 'Propel\Tests\Bookstore\Book', 'b');
        $c->where('b.Title = ?', 'foo');
        $c->setOffset(10);
        $c->leftJoin('b.Author a');

        $c2 = $c->useQuery('a');
        $this->assertTrue($c2 instanceof AuthorQuery, 'useQuery() returns a secondary Criteria');
        $this->assertEquals($c, $c2->getPrimaryCriteria(), 'useQuery() sets the primary Criteria os the secondary Criteria');
        $this->assertEquals(['a' => 'author'], $c2->getAliases(), 'useQuery() sets the secondary Criteria alias correctly');
        $c2->where('a.FirstName = ?', 'john');
        $c2->limit(5);

        $ret = $c2->endUse();
        $this->assertSame($c, $ret, 'endUse() returns the Primary Criteria');
        $this->assertEquals('Propel\Tests\Bookstore\Book', $c->getModelName(), 'endUse() returns the Primary Criteria');
        $c = $ret;

        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->find($con);

        if (!$this->runningOnMySQL()) {
            $expectedSQL = $this->getSql("SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM book LEFT JOIN author a ON (book.author_id=a.id) WHERE book.title = 'foo' AND a.first_name = 'john' LIMIT 5 OFFSET 10");
        } else {
            $expectedSQL = $this->getSql("SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM book LEFT JOIN author a ON (book.author_id=a.id) WHERE book.title = 'foo' AND a.first_name = 'john' LIMIT 10, 5");
        }

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'useQuery() and endUse() allow to merge a secondary criteria');
    }

    /**
     * @return void
     */
    public function testUseQueryCustomClass()
    {
        $c = new ModelCriteria('bookstore', 'Propel\Tests\Bookstore\Book', 'b');
        $c->where('b.Title = ?', 'foo');
        $c->setLimit(10);
        $c->leftJoin('b.Author a');

        $c2 = $c->useQuery('a', 'Propel\Tests\Runtime\ActiveQuery\ModelCriteriaForUseQuery');
        $this->assertTrue($c2 instanceof ModelCriteriaForUseQuery, 'useQuery() returns a secondary Criteria with the custom class');
        $c2->withNoName();
        $c = $c2->endUse();

        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->find($con);
        $expectedSQL = $this->getSql("SELECT book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id FROM book LEFT JOIN author a ON (book.author_id=a.id) WHERE book.title = 'foo' AND a.first_name IS NOT NULL  AND a.last_name IS NOT NULL LIMIT 10");
        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'useQuery() and endUse() allow to merge a custom secondary criteria');
    }

    /**
     * @return void
     */
    public function testUseQueryJoinWithFind()
    {
        $c = new ModelCriteria('bookstore', 'Propel\Tests\Bookstore\Review');
        $c->joinWith('Book');

        $c2 = $c->useQuery('Book');

        $joins = $c->getJoins();
        $this->assertEquals($c->getPreviousJoin(), null, 'The default value for previousJoin remains null');
        $this->assertEquals($c2->getPreviousJoin(), $joins['Book'], 'useQuery() sets the previousJoin');

        // join Book with Author, which is possible since previousJoin is set, which makes resolving of relations possible during hydration
        $c2->joinWith('Author');

        $c = $c2->endUse();

        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->find($con);

        $expectedSQL = $this->getSql('SELECT review.id, review.reviewed_by, review.review_date, review.recommended, review.status, review.book_id, book.id, book.title, book.isbn, book.price, book.publisher_id, book.author_id, author.id, author.first_name, author.last_name, author.email, author.age FROM review INNER JOIN book ON (review.book_id=book.id) INNER JOIN author ON (book.author_id=author.id)');

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'useQuery() and joinWith() can be used together and form a correct query');
    }

    /**
     * @return void
     */
    public function testUseQueryCustomRelationPhpName()
    {
        $c = new ModelCriteria('bookstore', 'Propel\Tests\Bookstore\BookstoreContest');
        $c->leftJoin('Propel\Tests\Bookstore\BookstoreContest.Work');
        $c2 = $c->useQuery('Work');
        $this->assertTrue($c2 instanceof BookQuery, 'useQuery() returns a secondary Criteria');
        $this->assertEquals($c, $c2->getPrimaryCriteria(), 'useQuery() sets the primary Criteria os the secondary Criteria');
        //$this->assertEquals(array('a' => 'author'), $c2->getAliases(), 'useQuery() sets the secondary Criteria alias correctly');
        $c2->where('Work.Title = ?', 'War And Peace');

        $c = $c2->endUse();
        $this->assertEquals('Propel\Tests\Bookstore\BookstoreContest', $c->getModelName(), 'endUse() returns the Primary Criteria');

        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->find($con);

        $expectedSQL = $this->getSql("SELECT bookstore_contest.bookstore_id, bookstore_contest.contest_id, bookstore_contest.prize_book_id FROM bookstore_contest LEFT JOIN book ON (bookstore_contest.prize_book_id=book.id) WHERE book.title = 'War And Peace'");

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'useQuery() and endUse() allow to merge a secondary criteria');
    }

    /**
     * @return void
     */
    public function testUseQueryCustomRelationPhpNameAndAlias()
    {
        $c = new ModelCriteria('bookstore', 'Propel\Tests\Bookstore\BookstoreContest');
        $c->leftJoin('Propel\Tests\Bookstore\BookstoreContest.Work w');
        $c2 = $c->useQuery('w');
        $this->assertTrue($c2 instanceof BookQuery, 'useQuery() returns a secondary Criteria');
        $this->assertEquals($c, $c2->getPrimaryCriteria(), 'useQuery() sets the primary Criteria os the secondary Criteria');
        $this->assertEquals(['w' => 'book'], $c2->getAliases(), 'useQuery() sets the secondary Criteria alias correctly');
        $c2->where('w.Title = ?', 'War And Peace');

        $c = $c2->endUse();
        $this->assertEquals('Propel\Tests\Bookstore\BookstoreContest', $c->getModelName(), 'endUse() returns the Primary Criteria');

        $con = Propel::getServiceContainer()->getConnection(BookTableMap::DATABASE_NAME);
        $c->find($con);

        $expectedSQL = $this->getSql("SELECT bookstore_contest.bookstore_id, bookstore_contest.contest_id, bookstore_contest.prize_book_id FROM bookstore_contest LEFT JOIN book w ON (bookstore_contest.prize_book_id=w.id) WHERE w.title = 'War And Peace'");

        $this->assertEquals($expectedSQL, $con->getLastExecutedQuery(), 'useQuery() and endUse() allow to merge a secondary criteria');
    }

    /**
     * @return void
     */
    public function testUseQueryInheritsPermanentOperator(): void
    {
        $params = [];
        $sql = BookQuery::create()
            ->combineFilters('OR')
                ->filterByPrice(42)
                ->useAuthorQuery()
                    ->filterByAge(43)
                    ->filterByFirstName('foo')
                ->endUse()
                ->filterByTitle('bar')
            ->endCombineFilters()
            ->filterByISBN('baz')
            ->createSelectSql($params);

        $expectedSQL = $this->getSql("SELECT  FROM book LEFT JOIN author ON (book.author_id=author.id) WHERE ((book.price=:p1 OR (author.age=:p2 OR author.first_name=:p3)) OR book.title=:p4) AND book.isbn=:p5");

        $this->assertEquals($expectedSQL, $sql);
    }
}
