<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\PropelTypes;

class ColumnCodeProducerFactory
{
    /**
     * @param \Propel\Generator\Model\Column $column
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $builder
     *
     * @return \Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes\ColumnCodeProducer
     */
    public static function create(Column $column, ObjectBuilder $builder): ColumnCodeProducer
    {
        $producer = $column->isLobType() && $column->getType() !== PropelTypes::OBJECT
            ? new LobColumnCodeProducer($column, $builder)
            : match ($column->getType()) {
                PropelTypes::DATE, PropelTypes::DATETIME, PropelTypes::TIME, PropelTypes::TIMESTAMP => new TemporalColumnCodeProducer($column, $builder),
                PropelTypes::OBJECT => new ObjectColumnCodeProducer($column, $builder),
                PropelTypes::PHP_ARRAY => new ArrayColumnCodeProducer($column, $builder),
                PropelTypes::JSON => new JsonColumnCodeProducer($column, $builder),
                PropelTypes::ENUM => new EnumColumnCodeProducer($column, $builder),
                PropelTypes::SET => new SetColumnCodeProducer($column, $builder),
                PropelTypes::BOOLEAN, PropelTypes::BOOLEAN_EMU => new BoolColumnCodeProducer($column, $builder),
                default => new ColumnCodeProducer($column, $builder),
            };

        return $column->isLazyLoad()
            ? new LazyLoadColumnCodeProducer($producer)
            : $producer;
    }
}
