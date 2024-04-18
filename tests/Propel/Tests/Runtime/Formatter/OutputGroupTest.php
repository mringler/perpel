<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Runtime\Formatter;

use Propel\Runtime\Collection\ObjectCollection;
use Propel\Tests\Bookstore\AcctAccessRole;
use Propel\Tests\Bookstore\AcctAuditLog;
use Propel\Tests\Bookstore\BookstoreEmployee;
use Propel\Tests\Bookstore\BookstoreEmployeeAccount;
use Propel\Tests\Helpers\Bookstore\BookstoreTestBase;

/**
 * @group database
 */
class OutputGroupTest extends BookstoreTestBase
{
    public function getPopulatedAccountObject()
    {
        $employee = (new BookstoreEmployee())->fromArray([
            'Id' => 1,
            'ClassKey' => 1,
            'Name' => 'le name',
            'JobTitle' => 'Manger',
        ]);
        $account = (new BookstoreEmployeeAccount())->fromArray([
            'EmployeeId' => 1,
            'Login' => 'le login',
            'Password' => 'le password',
            'Enabled' => true,
            'NotEnabled' => false,
            'Created' => '2024-04-18 11:52:13.533707',
            'RoleId' => 5,
            'Authenticator' => 'Password',
        ]);
        $role = (new AcctAccessRole())->fromArray([
            'Id' => 5,
            'Name' => 'le role name',
        ]);
        $logs = array_map(fn ($i) => (new AcctAuditLog())->fromArray([
            'Id' => $i,
            'Uid' => 1, // fk to account id
            'Message' => 'le message ' . $i,
        ]), range(1, 2));

        $account->setAcctAccessRole($role);
        $account->setBookstoreEmployee($employee);
        $account->setAcctAuditLogs(new ObjectCollection($logs));

        return $account;
    }

    public function outputGroupDataProvider()
    {
        $accountShort = [
            'EmployeeId' => 1,
            'Login' => 'le login',
        ];
        $accountPublic = [
            ...$accountShort,
            'Created' => '2024-04-18 11:52:13.533707',
            'RoleId' => 5,
            'Authenticator' => 'Password',
        ];

        $employeeShort = [
            'Id' => 1,
            'Name' => 'le name',
        ];

        $employeePublic = [
            ...$employeeShort,
            'ClassKey' => 1,
            'JobTitle' => 'Manger',
            'SupervisorId' => null,
            'Photo' => null,
            'BookstoreEmployeeAccount' => ['*RECURSION*'],
        ];

        $role = [
            'Id' => 5,
            'Name' => 'le role name',
            'BookstoreEmployeeAccounts' => [['*RECURSION*']],
        ];

        $logsPublic = [
            ['Id' => 1, 'Message' => 'le message 1'],
            ['Id' => 2, 'Message' => 'le message 2'],
        ];
        $logsShort = [
            ['Message' => 'le message 1'],
            ['Message' => 'le message 2'],
        ];

        return [
            [
                'public',
                [
                    ...$accountPublic,
                    'BookstoreEmployee' => $employeePublic,
                    'AcctAccessRole' => $role,
                    'AcctAuditLogs' => $logsPublic,
                ],
            ],
            [
                'short',
                [
                    ...$accountShort,
                    'BookstoreEmployee' => $employeeShort,
                ],
            ],
            [
                [
                    BookstoreEmployeeAccount::class => 'public',
                    BookstoreEmployee::class => 'public',
                    'default' => 'short',
                ],
                [
                    ...$accountPublic,
                    'BookstoreEmployee' => $employeePublic,
                    'AcctAccessRole' => $role,
                    'AcctAuditLogs' => $logsShort,
                ],
            ],
        ];
    }

    /**
     * @dataProvider outputGroupDataProvider
     *
     * @return void
     */
    public function testNums($outputGroup, array $expected)
    {
        $account = $this->getPopulatedAccountObject();
        $output = $account->toOutputGroup($outputGroup);

        $this->assertEquals($expected, $output);
    }
}
