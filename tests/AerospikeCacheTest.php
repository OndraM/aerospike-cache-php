<?php
declare(strict_types=1);

namespace Lmc\AerospikeCache;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class AerospikeCacheTest extends AbstractTestCase
{
    /** @var \Aerospike|MockObject $aerospikeMock */
    private $aerospikeMock;

    /** @var AerospikeCache */
    private $aerospikeCache;

    protected function setUp(): void
    {
        $this->aerospikeMock = $this->createMock(\Aerospike::class);
        $this->aerospikeCache = new AerospikeCache($this->aerospikeMock, 'test', 'cache');
    }

    /**
     * @dataProvider provideStatusCodes
     */
    public function testShouldConfirmItemPresence(int $statusCode, bool $expectedReturnedValue): void
    {
        $aerospikeKey = $this->createExpectedKey('foo', 'test', 'cache');

        $this->aerospikeMock->expects($this->once())
            ->method('initKey')
            ->willReturn($aerospikeKey);

        $this->aerospikeMock->expects($this->once())
            ->method('get')
            ->with($aerospikeKey)
            ->willReturn($statusCode);

        $hasItem = $this->aerospikeCache->hasItem('foo');

        $this->assertSame($expectedReturnedValue, $hasItem);
    }

    private function createExpectedKey(string $key, string $namespace, string $set): array
    {
        return ['ns' => $namespace, 'set' => $set, 'key' => $key];
    }

    /** @dataProvider provideItemsToSave */
    public function testShouldSaveItems(array $items, array $expectedLogs): void
    {
        $aerospikeNamespace = 'test';
        $aerospikeSetName = 'cache';
        $lifetime = 42;
        $expectedPolicy = [\Aerospike::OPT_POLICY_KEY => \Aerospike::POLICY_KEY_SEND];

        $logs = [];

        /** @var \Aerospike|MockObject $aerospikeMock */
        $aerospikeMock = $this->createMock(\Aerospike::class);
        $aerospikeCache = new AerospikeCache($aerospikeMock, $aerospikeNamespace, $aerospikeSetName, '', $lifetime);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->any())
            ->method('warning')
            ->willReturnCallback(function ($message, $context) use (&$logs): void {
                $logs[] = ['warning' => $message, 'key' => $context['key'] ?? ''];
            });
        $aerospikeCache->setLogger($logger);

        $aerospikeMock->expects($this->any())
            ->method('initKey')
            ->willReturnCallback(function ($namespace, $set, $key) {
                return $this->createExpectedKey($key, $namespace, $set);
            });

        $counter = 0;
        $aerospikeMock->expects($this->any())
            ->method('put')
            ->willReturnCallback(function (
                $aerospikeKey,
                $bins,
                $ttl,
                $options
            ) use (
                &$counter,
                $expectedPolicy,
                $lifetime,
                $aerospikeSetName,
                $aerospikeNamespace,
                $items
            ) {
                ['key' => $key, 'value' => $value, 'status' => $status] = $items[$counter++];

                $expectedKey = $this->createExpectedKey($key, $aerospikeNamespace, $aerospikeSetName);
                $this->assertSame($expectedKey, $aerospikeKey);

                $this->assertSame(['data' => $value], $bins);
                $this->assertSame($lifetime, $ttl);
                $this->assertSame($expectedPolicy, $options);

                return $status;
            });

        foreach ($items as ['key' => $key, 'value' => $value, 'status' => $status]) {
            $cacheItem = $aerospikeCache->getItem($key);
            $cacheItem->set($value);

            $expectedResult = $status === \Aerospike::OK;
            $this->assertSame($expectedResult, $aerospikeCache->save($cacheItem));
        }

        $this->assertSame($expectedLogs, $logs);
    }

    public function provideItemsToSave(): array
    {
        return [
            'save successfully no items' => [[], []],
            'save successfully 1 item' => [
                [
                    [
                        'key' => 'foo',
                        'value' => 'fooVal',
                        'status' => \Aerospike::OK,
                    ],
                ],
                [
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'foo'],
                ],
            ],
            'save successfully more items' => [
                [
                    [
                        'key' => 'foo',
                        'value' => 'fooVal',
                        'status' => \Aerospike::OK,
                    ],
                    [
                        'key' => 'bar',
                        'value' => 'barVal',
                        'status' => \Aerospike::OK,
                    ],
                    [
                        'key' => 'boo',
                        'value' => 'booVal',
                        'status' => \Aerospike::OK,
                    ],
                ],
                [
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'foo'],
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'bar'],
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'boo'],
                ],
            ],
            'save items with errors' => [
                [
                    [
                        'key' => 'foo',
                        'value' => 'fooVal',
                        'status' => \Aerospike::OK,
                    ],
                    [
                        'key' => 'bar',
                        'value' => 'barVal',
                        'status' => \Aerospike::ERR_CLIENT,
                    ],
                    [
                        'key' => 'boo',
                        'value' => 'booVal',
                        'status' => \Aerospike::OK,
                    ],
                ],
                [
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'foo'],
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'bar'],
                    ['warning' => 'Failed to save key "{key}" ({type})', 'key' => 'bar'],
                    ['warning' => 'Failed to fetch key "{key}"', 'key' => 'boo'],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideStatusCodes
     */
    public function testShouldClearWithEmptyNamespaceName(int $aerospikeStatusCode, bool $expectedClearSuccessful): void
    {
        $this->aerospikeMock->method('truncate')
            ->willReturn($aerospikeStatusCode);

        $clearSuccessful = $this->aerospikeCache->clear();

        $this->assertSame($expectedClearSuccessful, $clearSuccessful);
    }

    /**
     * @dataProvider provideStatusCodesForClearingNamespace
     */
    public function testShouldClearNamespace(
        int $statusCodeForRemove,
        int $statusCodeForScan,
        bool $expectedValue
    ): void {
        $aerospikeCache = new AerospikeCache($this->aerospikeMock, 'test', 'cache', 'testNamespace');

        $this->aerospikeMock->method('remove')
            ->willReturn($statusCodeForRemove);

        $this->aerospikeMock->method('scan')
            ->willReturnCallback(function ($namespace, $set, $callback) use ($statusCodeForScan) {
                $callback(['key' => ['key' => 'testNamespace::test']]);

                return $statusCodeForScan;
            });

        $clearResult = $aerospikeCache->clear();

        $this->assertSame($expectedValue, $clearResult);
    }

    public function provideStatusCodesForClearingNamespace(): array
    {
        return [
            [\Aerospike::OK, \Aerospike::OK, true],
            [\Aerospike::OK, \Aerospike::ERR_CLIENT, false],
            [\Aerospike::ERR_CLIENT, \Aerospike::ERR_CLIENT, false],
            [\Aerospike::ERR_CLIENT, \Aerospike::OK, false],
        ];
    }

    /**
     * @dataProvider provideStatusCodes
     */
    public function testShouldDeleteItem(int $aerospikeStatusCode, bool $expectedDeleteToSucced): void
    {
        $this->aerospikeMock = $this->createMock(\Aerospike::class);
        $this->aerospikeCache = new AerospikeCache($this->aerospikeMock, 'test', 'cache', 'testNamespace');

        $this->aerospikeMock->method('initKey')
            ->willReturn(['foo']);
        $this->aerospikeMock->method('remove')
            ->willReturn($aerospikeStatusCode);

        $deleteSuccessful = $this->aerospikeCache->deleteItem('foo');

        $this->assertSame($expectedDeleteToSucced, $deleteSuccessful);
    }

    public function provideStatusCodes(): array
    {
        return [
            [\Aerospike::OK, true],
            [\Aerospike::ERR_CLIENT, false],
        ];
    }

    public function testShouldReadExistingRecordFromAerospike(): void
    {
        $mockedAerospikeKey = $this->createExpectedKey('foo', 'aerospike', 'cache');

        $this->aerospikeMock->expects($this->once())
            ->method('initKey')
            ->willReturn($mockedAerospikeKey);

        $this->aerospikeMock->expects($this->once())
            ->method('getMany')
            ->with($this->equalTo([$mockedAerospikeKey]))
            ->willReturnCallback(function ($keys, &$records) {
                $records[] =
                    [
                        'key' => $keys[0],
                        'bins' => ['data' => 'bar'],
                        'metadata' => ['ttl' => 1000, 'generation' => 2],
                    ];

                return \Aerospike::OK;
            });

        $item = $this->aerospikeCache->getItem('foo');

        $this->assertTrue($item->isHit());
        $this->assertSame('bar', $item->get());
    }

    public function testShouldReadNonExistingRecordFromAerospike(): void
    {
        $mockedAerospikeKey = $this->createExpectedKey('foo', 'aerospike', 'cache');

        $this->aerospikeMock->expects($this->once())
            ->method('initKey')
            ->willReturn($mockedAerospikeKey);

        $this->aerospikeMock->expects($this->once())
            ->method('getMany')
            ->with($this->equalTo([$mockedAerospikeKey]))
            ->willReturnCallback(function ($keys, &$records) {
                $records[] =
                    [
                        'key' => $keys[0],
                        'bins' => null,
                        'metadata' => null,
                    ];

                return \Aerospike::OK;
            });

        $item = $this->aerospikeCache->getItem('foo');

        $this->assertFalse($item->isHit());
    }

    public function testShouldSaveUsingDataWrapperWithCorrectPolicy(): void
    {
        $reflection = new \ReflectionClass(AerospikeCache::class);

        $doSaveMethod = $reflection->getMethod('doSave');
        $doSaveMethod->setAccessible(true);

        $testValue = ['foo' => 'fooValue'];
        $testAerospikeKey = $this->createExpectedKey('foo', 'aerospike', 'cache');

        $this->aerospikeMock->method('initkey')
            ->willReturn($testAerospikeKey);

        $this->aerospikeMock->expects($this->once())
            ->method('put')
            ->with(
                $testAerospikeKey,
                ['data' => $testValue['foo']],
                0,
                [\Aerospike::OPT_POLICY_KEY => \Aerospike::POLICY_KEY_SEND]
            );

        $doSaveMethod->invokeArgs($this->aerospikeCache, [$testValue, 0]);
    }
}
