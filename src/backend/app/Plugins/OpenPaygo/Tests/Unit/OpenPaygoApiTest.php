<?php

declare(strict_types=1);

namespace App\Plugins\OpenPaygo\Tests\Unit;

use App\DTO\TransactionDataContainer;
use App\Enums\ManufacturerCapability;
use App\Exceptions\Manufacturer\ApiCallDoesNotSupportedException;
use App\Lib\IManufacturerAPI;
use App\Models\Device;
use App\Models\Manufacturer;
use App\Models\Token;
use App\Plugins\OpenPaygo\Exceptions\OpenPaygoCounterPersistenceException;
use App\Plugins\OpenPaygo\Exceptions\OpenPaygoDeviceConfigurationException;
use App\Plugins\OpenPaygo\Exceptions\OpenPaygoGeneratorException;
use App\Plugins\OpenPaygo\Http\Clients\OpenPaygoGeneratorClient;
use App\Plugins\OpenPaygo\Models\OpenPaygoDeviceConfiguration;
use App\Plugins\OpenPaygo\OpenPaygoApi;
use App\Plugins\OpenPaygo\Services\OpenPaygoDeviceConfigurationService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

class OpenPaygoApiTest extends TestCase {
    use MockeryPHPUnitIntegration;
    use RefreshMultipleDatabases;

    private OpenPaygoGeneratorClient $generatorClient;
    private OpenPaygoDeviceConfigurationService $configurationService;
    private OpenPaygoApi $api;

    protected function setUp(): void {
        parent::setUp();

        $this->generatorClient = Mockery::mock(OpenPaygoGeneratorClient::class);
        $this->configurationService = Mockery::mock(OpenPaygoDeviceConfigurationService::class);
        $this->api = new OpenPaygoApi($this->generatorClient, $this->configurationService);
    }

    public function testCapabilitiesAdvertiseOnlyProtocolOperationsWithVerifiedMpmSemantics(): void {
        $this->assertInstanceOf(IManufacturerAPI::class, $this->api);
        $this->assertSame([
            ManufacturerCapability::UnlockToken,
            ManufacturerCapability::ResetToken,
        ], $this->api->capabilities());
    }

    public function testUnlockUsesDisablePaygAndPersistsExactCounter(): void {
        $device = new Device();
        $configuration = [
            'secretKeyHex' => str_repeat('a', 32),
            'startingCode' => 321,
            'nextCounter' => 9,
        ];
        $this->configurationService->shouldReceive('getForDevice')->once()->with($device)->andReturn($configuration);
        $this->generatorClient->shouldReceive('generateToken')->once()->with([
            'secretKeyHex' => str_repeat('a', 32),
            'startingCode' => 321,
            'counter' => 9,
            'tokenType' => 'DISABLE_PAYG',
            'restrictedDigitSet' => false,
        ])->andReturn(['token' => '000123456', 'nextCounter' => 11]);
        $this->configurationService->shouldReceive('updateNextCounter')
            ->once()->with($device, 11)->andReturn(Mockery::mock(OpenPaygoDeviceConfiguration::class));

        $container = new TransactionDataContainer();
        $container->device = $device;
        $result = $this->api->unlockDevice($container);

        $this->assertSame('000123456', $result['token']);
        $this->assertSame(Token::TYPE_UNLOCK, $result['token_type']);
        $this->assertNull($result['token_unit']);
        $this->assertNull($result['token_amount']);
    }

    public function testClearUsesSetTimeZeroAndPersistsExactCounter(): void {
        $device = new Device();
        $this->configurationService->shouldReceive('getForDevice')->once()->with($device)->andReturn([
            'secretKeyHex' => str_repeat('b', 32),
            'startingCode' => 654,
            'nextCounter' => 12,
        ]);
        $this->generatorClient->shouldReceive('generateToken')->once()->with([
            'secretKeyHex' => str_repeat('b', 32),
            'startingCode' => 654,
            'counter' => 12,
            'tokenType' => 'SET_TIME',
            'restrictedDigitSet' => false,
            'value' => 0,
        ])->andReturn(['token' => '000987654', 'nextCounter' => 13]);
        $this->configurationService->shouldReceive('updateNextCounter')
            ->once()->with($device, 13)->andReturn(Mockery::mock(OpenPaygoDeviceConfiguration::class));

        $result = $this->api->clearDevice($device);

        $this->assertSame('000987654', $result['token']);
        $this->assertSame(Token::TYPE_RESET, $result['token_type']);
        $this->assertNull($result['token_unit']);
        $this->assertNull($result['token_amount']);
    }

    public function testMissingConfigurationFailsBeforeGeneratorInvocation(): void {
        $device = new Device();
        $this->configurationService->shouldReceive('getForDevice')
            ->once()->with($device)->andThrow(new OpenPaygoDeviceConfigurationException('configuration missing'));
        $this->generatorClient->shouldNotReceive('generateToken');

        $container = new TransactionDataContainer();
        $container->device = $device;

        $this->expectException(OpenPaygoDeviceConfigurationException::class);
        $this->api->unlockDevice($container);
    }

    public function testGeneratorFailureDoesNotPersistCounter(): void {
        $device = new Device();
        $this->configurationService->shouldReceive('getForDevice')->once()->with($device)->andReturn([
            'secretKeyHex' => str_repeat('c', 32),
            'startingCode' => 789,
            'nextCounter' => 4,
        ]);
        $this->generatorClient->shouldReceive('generateToken')
            ->once()->andThrow(new OpenPaygoGeneratorException('generator failed'));
        $this->configurationService->shouldNotReceive('updateNextCounter');
        $container = new TransactionDataContainer();
        $container->device = $device;

        $this->expectException(OpenPaygoGeneratorException::class);
        $this->api->unlockDevice($container);
    }

    public function testTokenIsNotReturnedWhenCounterPersistenceFails(): void {
        $device = new Device();
        $this->configurationService->shouldReceive('getForDevice')->once()->with($device)->andReturn([
            'secretKeyHex' => str_repeat('d', 32),
            'startingCode' => 987,
            'nextCounter' => 20,
        ]);
        $this->generatorClient->shouldReceive('generateToken')->once()->andReturn([
            'token' => '000111222',
            'nextCounter' => 21,
        ]);
        $this->configurationService->shouldReceive('updateNextCounter')
            ->once()->with($device, 21)->andThrow(new RuntimeException('database detail'));

        $container = new TransactionDataContainer();
        $container->device = $device;

        try {
            $this->api->unlockDevice($container);
            $this->fail('Expected counter persistence failure.');
        } catch (OpenPaygoCounterPersistenceException $exception) {
            $this->assertSame(
                'OpenPAYGO generated a token but could not persist its counter; the token was not returned.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('database detail', $exception->getMessage());
        }
    }

    public function testChargeFailsWithoutCastingUnmappedMpmCreditAmounts(): void {
        $this->generatorClient->shouldNotReceive('generateToken');
        $this->configurationService->shouldNotReceive('getForDevice');

        $this->expectException(ApiCallDoesNotSupportedException::class);
        $this->expectExceptionMessage('device time divider');

        $this->api->chargeDevice(new TransactionDataContainer());
    }

    public function testProviderAliasResolvesAndManufacturerRegistrationUsesTheSameApiName(): void {
        $this->assertInstanceOf(OpenPaygoApi::class, resolve('OpenPaygoApi'));

        $this->assertSame(0, Artisan::call('openpaygo:install'));

        $manufacturer = Manufacturer::query()->where('api_name', 'OpenPaygoApi')->firstOrFail();
        $this->assertSame('OpenPaygoApi', $manufacturer->api_name);
    }
}
