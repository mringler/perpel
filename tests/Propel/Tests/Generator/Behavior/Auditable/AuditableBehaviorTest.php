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

use Propel\Tests\Generator\Behavior\Auditable\SourceWithComplexAudit;
use Propel\Tests\Generator\Behavior\Auditable\SourceWithDefaultAudit;
use Propel\Tests\TestCaseFixtures;

/**
 * @group database
 */
class AuditableBehaviorTest extends TestCaseFixtures
{

    /**
     * @return void
     */
    public function testToArrayDoesNotExportInternalField(): void
    {
        $audit = new SourceWithDefaultAuditAudit();
        $audit->setInternalChangedValues(['key' => 'val']);

        $this->assertArrayNotHasKey('InternalChangedValues', $audit->toArray());
    }

    /**
     * @return void
     */
    public function testDefaultAudits(): void
    {
        $auditableOperation = 0;
        $source = AuditableObjectDataBuilder::createInitialSourceWithDefaultAudit();
        $this->assertDefaultAuditMatchesSource($source, $auditableOperation++, 'insert', []);

        AuditableObjectDataBuilder::updateDefaultColumns($source, 'update1');
        $this->assertDefaultAuditMatchesSource($source, $auditableOperation++, 'update', [
            'omited_column' => 'got changed',
            'omited_type_column' => 'got changed',
            'regular_column' => 'initial regular value',
        ]);

        AuditableObjectDataBuilder::updateDefaultColumns($source, 'update2', ['omited_column', 'omited_type_column']);
        $this->assertDefaultAuditMatchesSource($source, $auditableOperation++, 'update', [
            'regular_column' => 'update1 regular value',
        ]);

        $source->delete();
        $this->assertDefaultAuditMatchesSource($source, $auditableOperation++, 'delete', [
            'id' => $source->getId(),
            'omited_column' => 'got changed',
            'omited_type_column' => 'got changed',
            'regular_column' => 'update2 regular value',
        ]);
    }

    /**
     * @return void
     */
    public function testComplexAudits(): void
    {
        $auditableOperation = 0;
        $source = AuditableObjectDataBuilder::createInitialSourceWithComplexAudit();
        $this->assertComplexAuditMatchesSource($source, $auditableOperation++, 'insert', []);

        AuditableObjectDataBuilder::updateDefaultColumns($source, 'update1');
        $this->assertComplexAuditMatchesSource($source, $auditableOperation++, 'update', [
            'omited_column' => 'got changed',
            'omited_type_column' => 'got changed',
            'regular_column' => 'initial regular value',
        ]);

        AuditableObjectDataBuilder::updateDefaultColumns($source, 'update2', ['omited_column', 'omited_type_column']);
        $this->assertComplexAuditMatchesSource($source, $auditableOperation++, 'update', [
            'regular_column' => 'update1 regular value',
        ]);

        $source->delete();
        $this->assertComplexAuditMatchesSource($source, $auditableOperation++, 'delete', [
            'id' => $source->getId(),
            'omited_column' => 'got changed',
            'omited_type_column' => 'got changed',
            'regular_column' => 'update2 regular value',
        ]);
    }

    /**
     * @return void
     */
    public function testRestoreAudit(): void
    {
        $source = AuditableObjectDataBuilder::createInitialSourceWithComplexAudit();
        $sourceId = $source->getId();
        AuditableObjectDataBuilder::updateDefaultColumns($source, 'update1');
        AuditableObjectDataBuilder::updateDefaultColumns($source, 'update2', ['omited_column', 'omited_type_column']);
        $source->delete();
        $audit = $source->restoreAudit();
        $changedValues = array_map(fn($audit) => $audit->getLeChanges(), $audit->getArrayCopy());

        $expectedChanges = [
            [
                'id' => $sourceId,
                'regular_column' => 'update2 regular value',
                'omited_column' => 'got changed',
                'omited_type_column' => 'got changed',
            ],
            [
                'regular_column' => 'update2 regular value',
            ],
            [
                'regular_column' => 'update1 regular value',
                'omited_column' => 'got changed',
                'omited_type_column' => 'got changed',
            ],
            [
                'id' => $sourceId,
                'regular_column' => 'initial regular value',
                'omited_column' => 'got changed',
                'omited_type_column' => 'got changed',
            ]
        ];

        $this->assertSame($expectedChanges, $changedValues);
    }

    /**
     * @param SourceWithDefaultAudit $source
     * @param int $auditNumber
     * @param string $event
     * @param array $changedValues
     * 
     * @return void
     */
    public function assertDefaultAuditMatchesSource(SourceWithDefaultAudit $source, int $auditNumber, string $event, array $changedValues): void
    {
        $audits = $source->getSourceWithDefaultAuditAudits();
        $this->assertCount($auditNumber + 1, $audits);
        $audit = $audits[$auditNumber];
        $audit->reload();
        $expectedExport = [
            'SourceWithDefaultAuditId' => $source->getId(),
            'AuditId' => $audit->getAuditId(),
            'AuditedAt' => $audit->getAuditedAt('Y-m-d H:i:s.u'),
            'AuditEvent' => $event,
        ];
        $this->assertSame($expectedExport, $audit->toArray());
        $this->assertNotNull($audit->getAuditedAt(), 'Audit date should not be null');

        $this->assertEqualsCanonicalizing($changedValues, $audit->getInternalChangedValues());
    }

    /**
     * @param SourceWithComplexAudit $source
     * @param int $auditNumber
     * @param string $event
     * @param array $changedValues
     * 
     * @return void
     */
    public function assertComplexAuditMatchesSource(SourceWithComplexAudit $source, int $auditNumber, string $event, array $changedValues): void
    {
        if ($event !== 'delete') {
            $source->reload();
        }
        $this->assertEquals($auditNumber + 1, $source->getNumberOfAudits());

        $audits = $source->getLeAuditCompliquesRelatedByLeSourceId();
        $this->assertCount($auditNumber + 1, $audits);
        $audit = $audits[$auditNumber];
        $audit->reload();

        $expectedExport = [
            'LeSourceId' => $source->getId(),
            'LeId' => $audit->getLeId(),
            'FkToSourceId' => null,
            'LeAt' => $audit->getLeAt('Y-m-d H:i:s.u'),
            'LeWhen' => $event,
        ];
        $this->assertSame($expectedExport, $audit->toArray());
        $this->assertInstanceOf(\DateTime::class, $audit->getLeAt(), 'Audit date should not be null');
        $this->assertEqualsCanonicalizing($changedValues, $audit->getInternalLeChanges());
    }
}
