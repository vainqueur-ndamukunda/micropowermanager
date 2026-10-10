<?php

declare(strict_types=1);

namespace App\Plugins\OpenPaygo\Tests\Unit;

use App\DTO\TransactionDataContainer;
use App\Enums\DeviceType;
use App\Enums\ManufacturerCapability;
use App\Exceptions\Manufacturer\ApiCallDoesNotSupportedException;
use App\Lib\IManufacturerAPI;
use App\Models\Device;
use App\Models\Token;
use App\Models\Transaction\Transaction;
use App\Plugins\OpenPaygo\OpenPaygoApi;
use App\Plugins\OpenPaygo\Services\OpenPaygoIssuanceService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

class OpenPaygoApiTest extends TestCase {
    use MockeryPHPUnitIntegration;
    use RefreshMultipleDatabases;

    public function testCapabilitiesAdvertiseOnlyVerifiedProtocolOperations(): void {
        $api = new OpenPaygoApi(Mockery::mock(OpenPaygoIssuanceService::class));

        $this->assertInstanceOf(IManufacturerAPI::class, $api);
        $this->assertSame([
            ManufacturerCapability::UnlockToken,
            ManufacturerCapability::ResetToken,
        ], $api->capabilities());
    }

    public function testProviderAliasResolvesTheReservationBackedApi(): void {
        $this->assertInstanceOf(OpenPaygoApi::class, resolve('OpenPaygoApi'));
    }

    public function testUnlockUsesItsTransactionReservation(): void {
        $device = $this->timeBasedDevice();
        $transaction = new Transaction();
        $token = new Token(['token' => '123456789', 'token_type' => Token::TYPE_UNLOCK]);
        $issuanceService = Mockery::mock(OpenPaygoIssuanceService::class);
        $issuanceService->shouldReceive('issue')->once()->with($transaction, $device, 'unlock')->andReturn($token);
        $container = new TransactionDataContainer();
        $container->transaction = $transaction;
        $container->device = $device;

        $result = (new OpenPaygoApi($issuanceService))->unlockDevice($container);

        $this->assertSame('123456789', $result['token']);
        $this->assertSame(Token::TYPE_UNLOCK, $result['token_type']);
    }

    public function testResetCannotBeIssuedWithoutTransactionContext(): void {
        $api = new OpenPaygoApi(Mockery::mock(OpenPaygoIssuanceService::class));

        $this->expectException(ApiCallDoesNotSupportedException::class);
        $this->expectExceptionMessage('requires a transaction context');
        $api->clearDevice($this->timeBasedDevice());
    }

    public function testChargeRemainsUnsupported(): void {
        $api = new OpenPaygoApi(Mockery::mock(OpenPaygoIssuanceService::class));

        $this->expectException(ApiCallDoesNotSupportedException::class);
        $this->expectExceptionMessage('device time divider');
        $api->chargeDevice(new TransactionDataContainer());
    }

    private function timeBasedDevice(): Device {
        $device = new Device();
        $device->device_type = DeviceType::SolarHomeSystem->value;

        return $device;
    }
}
