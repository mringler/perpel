
/**
 * Removes the object from the database without archiving it.
 *
 * @param \Propel\Runtime\Connection\ConnectionInterface|null $con Optional connection object
 *
 * @return $this|<?php echo $objectClassName ?> The current object (for fluent API support)
 */
public function deleteWithoutArchive(?ConnectionInterface $con = null)
{
    $this->archiveOnDelete = false;

    return $this->delete($con);
}
