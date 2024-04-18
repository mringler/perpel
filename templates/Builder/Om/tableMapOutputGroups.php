
    /**
     * Output group definitions.
     *
     * @var array<string, array{'column_index': array<int>, 'relation': array<int>}>
     */
    protected static $outputGroups = [
<?php foreach ($columnIndexesByOutputGroup as $outputGroupName => $values):?>
        '<?= $outputGroupName ?>' => [
            'column_index' => [<?= implode(', ', $values['column_index'] ?? []) ?>],
            'relation' => [
<?php foreach($values['relation'] ?? [] as $relationName): ?>
                '<?= $relationName ?>' => 1,
<?php endforeach; ?>
            ],
        ],
<?php endforeach; ?>
    ];

    /**
     * Get column indexes for the given output group name.
     *
     * @param string $outputGroupName Name of the output group as specified in schema.xml.
     *
     * @return array<string, array{'column_index': array<int>, 'relation': array<int>|null}>
     */
    public static function getOutputGroupData(string $outputGroupName): array
    {
        return self::$outputGroups[$outputGroupName] ?? [
            'column_index' => self::$fieldKeys[self::TYPE_NUM],
            'relation' => null,
        ];
    }
