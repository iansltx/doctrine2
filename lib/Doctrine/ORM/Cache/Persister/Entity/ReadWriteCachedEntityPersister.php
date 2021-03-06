<?php


declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\Cache\EntityCacheKey;

/**
 * Specific read-write entity persister
 *
 * @author Fabio B. Silva <fabio.bat.silva@gmail.com>
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @since 2.5
 */
class ReadWriteCachedEntityPersister extends AbstractEntityPersister
{
    /**
     * @param \Doctrine\ORM\Persisters\Entity\EntityPersister $persister The entity persister to cache.
     * @param \Doctrine\ORM\Cache\ConcurrentRegion            $region    The entity cache region.
     * @param \Doctrine\ORM\EntityManagerInterface            $em        The entity manager.
     * @param \Doctrine\ORM\Mapping\ClassMetadata             $class     The entity metadata.
     */
    public function __construct(EntityPersister $persister, ConcurrentRegion $region, EntityManagerInterface $em, ClassMetadata $class)
    {
        parent::__construct($persister, $region, $em, $class);
    }

    /**
     * {@inheritdoc}
     */
    public function afterTransactionComplete()
    {
        $isChanged = true;

        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $item) {
                $this->region->evict($item['key']);

                $isChanged = true;
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $item) {
                $this->region->evict($item['key']);

                $isChanged = true;
            }
        }

        if ($isChanged) {
            $this->timestampRegion->update($this->timestampKey);
        }

        $this->queuedCache = [];
    }

    /**
     * {@inheritdoc}
     */
    public function afterTransactionRolledBack()
    {
        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        $this->queuedCache = [];
    }

    /**
     * {@inheritdoc}
     */
    public function delete($entity)
    {
        $uow     = $this->em->getUnitOfWork();
        $key     = new EntityCacheKey($this->class->getRootClassName(), $uow->getEntityIdentifier($entity));
        $lock    = $this->region->lock($key);
        $deleted = $this->persister->delete($entity);

        if ($deleted) {
            $this->region->evict($key);
        }

        if ($lock === null) {
            return $deleted;
        }

        $this->queuedCache['delete'][] = [
            'lock'   => $lock,
            'key'    => $key
        ];

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function update($entity)
    {
        $uow  = $this->em->getUnitOfWork();
        $key  = new EntityCacheKey($this->class->getRootClassName(), $uow->getEntityIdentifier($entity));
        $lock = $this->region->lock($key);

        $this->persister->update($entity);

        if ($lock === null) {
            return;
        }

        $this->queuedCache['update'][] = [
            'lock'   => $lock,
            'key'    => $key
        ];
    }
}
