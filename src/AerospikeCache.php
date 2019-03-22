<?php declare(strict_types=1);

namespace Lmc\AerospikeCache;

use Symfony\Component\Cache\Adapter\AbstractAdapter;

class AerospikeCache extends AbstractAdapter
{
    private const WRAPPER_NAME = 'data';

    /** @var \Aerospike */
    private $aerospike;

    /** @var string */
    private $namespace;

    /** @var string */
    private $set;

    public function __construct(
        \Aerospike $aerospike,
        string $namespace,
        string $set,
        string $cacheNamespace = '',
        int $defaultLifetime = 0
    ) {
        $this->aerospike = $aerospike;
        $this->namespace = $namespace;
        $this->set = $set;
        parent::__construct($cacheNamespace, $defaultLifetime);
    }

    protected function doFetch(array $ids): array
    {
        $result = [];

        $keys = $this->initializeKeysForAerospike($ids);

        $this->aerospike->getMany($keys, $records);

        foreach ($records as $record) {
            if ($record['metadata'] !== null) {
                $result[$record['key']['key']] = $record['bins'][self::WRAPPER_NAME] ?? null;
            }
        }

        return $result;
    }

    private function initializeKeysForAerospike(array $ids): array
    {
        return array_map(
            function ($id) {
                return $this->createKey($id);
            },
            $ids
        );
    }

    protected function doHave($id)
    {
        return $this->aerospike->get($this->createKey($id), $ignoredRecord) === \Aerospike::OK;
    }

    protected function doClear($namespace = ''): bool
    {
        if ($namespace === '') {
            $statusCode = $this->aerospike->truncate($this->namespace, $this->set, 0);
            $cleared = $this->isStatusOkOrNotFound($statusCode);
        } else {
            $removedAllRecords = true;
            $clearNamespace = function ($record) use ($namespace, &$removedAllRecords): void {
                if ($namespace === mb_substr($record['key']['key'], 0, mb_strlen($namespace))) {
                    $statusCodeFromRemove = $this->aerospike->remove($record['key']);

                    if (!$this->isStatusOkOrNotFound($statusCodeFromRemove)) {
                        $removedAllRecords = false;
                    }
                }
            };

            $statusCodeFromScan = $this->aerospike->scan($this->namespace, $this->set, $clearNamespace);
            $cleared = $removedAllRecords && $this->isStatusOkOrNotFound($statusCodeFromScan);
        }

        return $cleared;
    }

    protected function doDelete(array $ids): bool
    {
        $removedAllItems = true;

        foreach ($ids as $id) {
            $statusCode = $this->aerospike->remove($this->createKey($id));
            if (!$this->isStatusOkOrNotFound($statusCode)) {
                $removedAllItems = false;
            }
        }

        return $removedAllItems;
    }

    /**
     * @param int $lifetime
     * @return string[] keys of values that failed during save operation
     */
    protected function doSave(array $values, $lifetime): array
    {
        $failed = [];
        foreach ($values as $key => $value) {
            $data = [self::WRAPPER_NAME => $value];
            $statusCode = $this->aerospike->put($this->createKey($key), $data, $lifetime, [\Aerospike::OPT_POLICY_KEY => \Aerospike::POLICY_KEY_SEND]);
            if ($statusCode !== \Aerospike::OK) {
                $failed[] = $key;
            }
        }

        return $failed;
    }

    private function createKey(string $key): array
    {
        return $this->aerospike->initKey($this->namespace, $this->set, $key);
    }

    private function isStatusOkOrNotFound(int $statusCode): bool
    {
        return $statusCode === \Aerospike::ERR_RECORD_NOT_FOUND || $statusCode === \Aerospike::OK;
    }
}
