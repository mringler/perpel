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

use Propel\Tests\Generator\Behavior\Auditable\SourceWithDefaultAudit;

/**
 */
class AuditableObjectDataBuilder
{
    /**
     * @param string $updateId
     * 
     * @return SourceWithDefaultAudit
     */
    public static function createInitialSourceWithDefaultAudit(string $updateId = 'initial'): SourceWithDefaultAudit
    {
        $source = new SourceWithDefaultAudit();
        static::updateDefaultColumns($source, 'initial');

        return $source;
    }

    /**
     * @param string $updateId
     * 
     * @return SourceWithComplexAudit
     */
    public static function createInitialSourceWithComplexAudit(string $updateId = 'initial'): SourceWithComplexAudit
    {
        $source = new SourceWithComplexAudit();
        static::updateDefaultColumns($source, 'initial');

        return $source;
    }

    /**
     * @param SourceWithDefaultAudit|SourceWithComplexAudit $source
     * @param string $updateId
     * @param array $skipColumns
     * 
     * @return void
     */
    public static function updateDefaultColumns($source, string $updateId, array $skipColumns = []): void
    {
        in_array('regular_column', $skipColumns) || $source->setRegularColumn($updateId . ' regular value');
        in_array('ignored_column', $skipColumns) || $source->setIgnoredColumn($updateId . ' ignored value');
        in_array('omited_column', $skipColumns) || $source->setOmitedColumn($updateId . ' omited value');
        in_array('omited_type_column', $skipColumns) || $source->setOmitedTypeColumn(fopen('data://text/plain,' . $updateId . ' omited type', 'r'));
        $source->save();
    }
}
