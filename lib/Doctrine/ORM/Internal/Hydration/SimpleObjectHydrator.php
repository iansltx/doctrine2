<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\ORM\Mapping\InheritanceType;
use PDO;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;

class SimpleObjectHydrator extends AbstractHydrator
{
    /**
     * @var ClassMetadata
     */
    private $class;

    /**
     * {@inheritdoc}
     */
    protected function prepare()
    {
        if (count($this->rsm->aliasMap) !== 1) {
            throw new \RuntimeException("Cannot use SimpleObjectHydrator with a ResultSetMapping that contains more than one object result.");
        }

        if ($this->rsm->scalarMappings) {
            throw new \RuntimeException("Cannot use SimpleObjectHydrator with a ResultSetMapping that contains scalar mappings.");
        }

        $this->class = $this->getClassMetadata(reset($this->rsm->aliasMap));
    }

    /**
     * {@inheritdoc}
     */
    protected function cleanup()
    {
        parent::cleanup();

        $this->uow->triggerEagerLoads();
        $this->uow->hydrationComplete();
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateAllData()
    {
        $result = [];

        while ($row = $this->stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->hydrateRowData($row, $result);
        }

        $this->em->getUnitOfWork()->triggerEagerLoads();

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateRowData(array $sqlResult, array &$result)
    {
        $entityName = $this->class->getClassName();
        $data       = [];

        // We need to find the correct entity class name if we have inheritance in resultset
        if ($this->class->inheritanceType !== InheritanceType::NONE) {
            $discrColumnName = $this->platform->getSQLResultCasing($this->class->discriminatorColumn->getColumnName());

            // Find mapped discriminator column from the result set.
            if ($metaMappingDiscrColumnName = array_search($discrColumnName, $this->rsm->metaMappings)) {
                $discrColumnName = $metaMappingDiscrColumnName;
            }

            if ( ! isset($sqlResult[$discrColumnName])) {
                throw HydrationException::missingDiscriminatorColumn($entityName, $discrColumnName, key($this->rsm->aliasMap));
            }

            if ($sqlResult[$discrColumnName] === '') {
                throw HydrationException::emptyDiscriminatorValue(key($this->rsm->aliasMap));
            }

            $discrMap = $this->class->discriminatorMap;

            if ( ! isset($discrMap[$sqlResult[$discrColumnName]])) {
                throw HydrationException::invalidDiscriminatorValue($sqlResult[$discrColumnName], array_keys($discrMap));
            }

            $entityName = $discrMap[$sqlResult[$discrColumnName]];

            unset($sqlResult[$discrColumnName]);
        }

        foreach ($sqlResult as $column => $value) {
            // An ObjectHydrator should be used instead of SimpleObjectHydrator
            if (isset($this->rsm->relationMap[$column])) {
                throw new \Exception(sprintf('Unable to retrieve association information for column "%s"', $column));
            }

            $cacheKeyInfo = $this->hydrateColumnInfo($column);

            if ( ! $cacheKeyInfo) {
                continue;
            }

            // Check if value is null before conversion (because some types convert null to something else)
            $valueIsNull = null === $value;

            // Convert field to a valid PHP value
            if (isset($cacheKeyInfo['type'])) {
                $type  = $cacheKeyInfo['type'];
                $value = $type->convertToPHPValue($value, $this->platform);
            }

            $fieldName = $cacheKeyInfo['fieldName'];

            // Prevent overwrite in case of inherit classes using same property name (See AbstractHydrator)
            if ( ! isset($data[$fieldName]) || ! $valueIsNull) {
                $data[$fieldName] = $value;
            }
        }

        $uow    = $this->em->getUnitOfWork();
        $entity = $uow->createEntity($entityName, $data, $this->hints);

        $result[] = $entity;

        if (isset($this->hints[Query::HINT_INTERNAL_ITERATION]) && $this->hints[Query::HINT_INTERNAL_ITERATION]) {
            $this->uow->hydrationComplete();
        }
    }
}
