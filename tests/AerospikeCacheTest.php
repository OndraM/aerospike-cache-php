<?php
declare(strict_types=1);

namespace Lmc\AerospikeCache;

use PHPUnit\Framework\MockObject\MockObject;

class AerospikeCacheTest extends AbstractTestCase
{

    /** @var MockObject */
    private $aerospikeMock;

    /** @var AerospikeCache */
    private $aerospikeCache;

    protected function setUp()
    {
        $this->aerospikeMock = $this->createMock(\Aerospike::class);
        $this->aerospikeCache = new AerospikeCache($this->aerospikeMock, 'test', 'cache');
    }

    protected function tearDown()
    {
        unset($this->aerospikeMock);
    }

    /**
     * @dataProvider provideStatusCodes
     */
    public function testShouldConfirmItemPresence(int $statusCode, bool $expectedReturnedValue): void
    {
        $aerospikeKey = ['ns' => 'test', 'set' => 'cache', 'key' => 'foo'];
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

    /**
     * @dataProvider provideStatusCodes
     */
    public function testShouldSaveItem(int $aerospikeStatusCode, bool $expectedSaveSuccessful): void
    {
        $this->aerospikeMock->method('put')
                ->willReturn($aerospikeStatusCode);

        $this->aerospikeMock->method('get')
                ->willReturn(\Aerospike::OK);

        $aerospikeKey = ['ns' => 'test', 'set' => 'cache', 'key' => 'foo'];
        $this->aerospikeMock->method('initKey')
                ->willReturn($aerospikeKey);

        $cacheItem = $this->aerospikeCache->getItem('foo');
        $saveSuccessful = $this->aerospikeCache->save($cacheItem);

        $this->assertSame($expectedSaveSuccessful, $saveSuccessful);
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
    public function testShouldClearNamespace(int $statusCodeForRemove, int $statusCodeForScan, bool $expectedValue): void
    {
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
        $mockedAerospikeKey = ['ns' => 'test', 'set' => 'cache', 'key' => 'foo'];

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

        $items = iterator_to_array($this->aerospikeCache->getItems(['foo']));

        $this->assertTrue($items['foo']->isHit());
        $this->assertSame('bar',$items['foo']->get());
    }

    public function testShouldReadNonExistingRecordFromAerospike(): void
    {

        $mockedAerospikeKey = ['ns' => 'test', 'set' => 'cache', 'key' => 'foo'];

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

        $items = iterator_to_array($this->aerospikeCache->getItems(['foo']));

        $this->assertFalse($items['foo']->isHit());
    }

    public function testSavedDataShouldHaveDataWrapper(): void
    {
        $reflection = new \ReflectionClass(AerospikeCache::class);

        $doSaveMethod = $reflection->getMethod('doSave');
        $doSaveMethod->setAccessible(true);

        $testValue = ['testKey' => 'testValue'];
        $testAerospikeKey = ['ns' => 'aerospike', 'set' => 'cache', 'key' => 'testKey'];

        $this->aerospikeMock->method('initkey')->willReturn($testAerospikeKey);

        $this->aerospikeMock->expects($this->once())->method('put')-> with(
            $testAerospikeKey,
            ['data' => $testValue['testKey']],
            0,
            [\Aerospike::OPT_POLICY_KEY => \Aerospike::POLICY_KEY_SEND]
        );

        $doSaveMethod->invokeArgs($this->aerospikeCache, [$testValue, 0]);
    }
}
