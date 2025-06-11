
/**
 * Returns the current translation
 *
 * @param \Propel\Runtime\Connection\ConnectionInterface|null $con an optional connection object
 *
 * @return <?= $i18nTablePhpName ?> 
 */
public function getCurrentTranslation(?ConnectionInterface $con = null)
{
    return $this->getTranslation($this->get<?= $localeColumnName ?>(), $con);
}
