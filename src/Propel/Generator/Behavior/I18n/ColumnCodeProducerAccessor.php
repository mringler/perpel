<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Generator\Behavior\I18n;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes\ColumnCodeProducer;
use Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes\ColumnCodeProducerFactory;
use Propel\Generator\Model\Column;

class ColumnCodeProducerAccessor extends ColumnCodeProducer
{
    /**
     * @var \Propel\Generator\Builder\Om\ObjectBuilder\ColumnTypes\ColumnCodeProducer
     */
    protected ColumnCodeProducer $childProducer;

    /**
     * @param \Propel\Generator\Model\Column $column
     * @param \Propel\Generator\Builder\Om\ObjectBuilder $builder
     */
    public function __construct(Column $column, ObjectBuilder $builder)
    {
        parent::__construct($column, $builder);
        $this->childProducer = ColumnCodeProducerFactory::create($column, $builder);
    }

    /**
     * @return string
     */
    public function getAccessorComment(): string
    {
        $comment = '';
        $this->childProducer->addAccessorComment($comment);
        $comment = preg_replace('/^\s*/m', '', $comment);

        return "$comment\n";
    }

    /**
     * @return array{string, string}
     */
    public function getAccessorFunctionStatement(): array
    {
        $functionStatement = '';
        $this->childProducer->addAccessorOpen($functionStatement);
        $functionStatement = preg_replace('/^\s*/m', '', $functionStatement);

        preg_match_all('/\$[a-z]+/i', $functionStatement, $params);
        $params = implode(', ', $params[0]);

        return [$functionStatement, $params];
    }

    /**
     * @param string $i18nTablePhpName
     * @param string $tablePhpName
     *
     * @return string
     */
    public function getMutatorComment(string $i18nTablePhpName, string $tablePhpName): string
    {
        $comment = '';
        $this->childProducer->addMutatorComment($comment);
        $comment = preg_replace('/^\s*/m', '', $comment);
        /** @var string $comment */
        $comment = str_replace('@return $this|' . $i18nTablePhpName, '@return $this|' . $tablePhpName, $comment);

        return "$comment\n";
    }

    /**
     * @return array{string, string}
     */
    public function getMutatorFunctionStatement(): array
    {
        $functionStatement = '';
        $this->childProducer->addMutatorMethodHeader($functionStatement);
        $functionStatement = preg_replace('/^\s*/m', '', $functionStatement);
        preg_match_all('/\$[a-z]+/i', $functionStatement, $params);
        $params = implode(', ', $params[0]);

        return [$functionStatement, $params];
    }
}
