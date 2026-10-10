<?php

declare(strict_types=1);

namespace App\Plugins\OpenPaygo\Tests\Unit;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\Token;
use App\Models\Transaction\Transaction;
use App\Plugins\OpenPaygo\Exceptions\OpenPaygoIssuanceException;
use App\Plugins\OpenPaygo\Http\Clients\OpenPaygoGeneratorClient;
use App\Plugins\OpenPaygo\Models\OpenPaygoDeviceConfiguration;
use App\Plugins\OpenPaygo\Models\OpenPaygoIssuanceReservation;
use App\Plugins\OpenPaygo\Services\OpenPaygoDeviceConfigurationService;
use App\Plugins\OpenPaygo\Services\OpenPaygoIssuanceService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

class OpenPaygoIssuanceServiceTest extends TestCase {
    use MockeryPHPUnitIntegration;
    use RefreshMultipleDatabases;

    public function testASecondTransactionCannotIssueWhileTheDeviceHasAnUncertainReservation(): void {
        $device = $this->createDevice();
        $this->saveConfiguration($device);
        $firstTransaction = $this->createTransaction($device);
        $secondTransaction = $this->createTransaction($device);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldReceive('generateToken')->once()->with([
            'secretKeyHex' => '0123456789abcdef0123456789abcdef',
            'startingCode' => 123,
            'counter' => 6,
            'tokenType' => 'DISABLE_PAYG',
            'restrictedDigitSet' => false,
        ])->andThrow(new \RuntimeException('timeout'));
        $service = new OpenPaygoIssuanceService($generator);

        try {
            $service->issue($firstTransaction, $device, 'unlock');
            $this->fail('Expected the timed out request to remain uncertain.');
        } catch (OpenPaygoIssuanceException $exception) {
            $this->assertStringContainsString('outcome is uncertain', $exception->getMessage());
        }

        try {
            $service->issue($secondTransaction, $device, 'unlock');
            $this->fail('Expected the active device reservation to block another transaction.');
        } catch (OpenPaygoIssuanceException $exception) {
            $this->assertStringContainsString('unresolved', $exception->getMessage());
        }

        $this->assertSame(1, OpenPaygoIssuanceReservation::query()->where('device_id', $device->id)->count());
    }

    public function testRepeatedCompletedTransactionReturnsStoredTokenWithoutAnotherGeneratorCall(): void {
        $device = $this->createDevice();
        $this->saveConfiguration($device);
        $transaction = $this->createTransaction($device);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldReceive('generateToken')->once()->andReturn([
            'token' => '123456789',
            'nextCounter' => 7,
        ]);
        $service = new OpenPaygoIssuanceService($generator);

        $firstToken = $service->issue($transaction, $device, 'unlock');
        $secondToken = $service->issue($transaction, $device, 'unlock');

        $this->assertSame($firstToken->id, $secondToken->id);
        $this->assertSame(7, (new OpenPaygoDeviceConfigurationService())->getForDevice($device)['nextCounter']);
        $this->assertSame(
            OpenPaygoIssuanceReservation::STATE_COMPLETED,
            OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('state'),
        );
    }

    public function testGeneratorReturnedCounterMayReachDocumentedBoundary(): void {
        $device = $this->createDevice();
        $this->saveConfiguration($device, 100000);
        $transaction = $this->createTransaction($device);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldReceive('generateToken')->once()->andReturn([
            'token' => 'boundary-token',
            'nextCounter' => 100002,
        ]);
        $service = new OpenPaygoIssuanceService($generator);

        $token = $service->issue($transaction, $device, 'unlock');

        $this->assertSame('boundary-token', $token->token);
        $this->assertSame(100002, (new OpenPaygoDeviceConfigurationService())->getForDevice($device)['nextCounter']);
    }

    public function testCounterBeyondGeneratorInputLimitBlocksBeforeExternalCall(): void {
        $device = $this->createDevice();
        $configuration = $this->saveConfiguration($device, 100000);
        $configuration->next_counter = 100001;
        $configuration->save();
        $transaction = $this->createTransaction($device);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldNotReceive('generateToken');
        $service = new OpenPaygoIssuanceService($generator);

        $this->expectException(OpenPaygoIssuanceException::class);
        $this->expectExceptionMessage('counter is exhausted');
        $service->issue($transaction, $device, 'unlock');
    }

    public function testTransactionCannotIssueForAnotherDevice(): void {
        $device = $this->createDevice();
        $otherDevice = $this->createDevice();
        $this->saveConfiguration($device);
        $transaction = $this->createTransaction($device);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldNotReceive('generateToken');

        $this->expectException(OpenPaygoIssuanceException::class);
        $this->expectExceptionMessage('does not belong to this device');
        (new OpenPaygoIssuanceService($generator))->issue($transaction, $otherDevice, 'unlock');
    }

    public function testDuplicateUncertainRequestDoesNotCallGeneratorAgain(): void {
        $device = $this->createDevice();
        $this->saveConfiguration($device);
        $transaction = $this->createTransaction($device);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldReceive('generateToken')->once()->with([
            'secretKeyHex' => '0123456789abcdef0123456789abcdef',
            'startingCode' => 123,
            'counter' => 6,
            'tokenType' => 'SET_TIME',
            'restrictedDigitSet' => false,
            'value' => 0,
        ])->andThrow(new \RuntimeException('connection lost'));
        $service = new OpenPaygoIssuanceService($generator);

        try {
            $service->issue($transaction, $device, 'reset');
            $this->fail('Expected the generator timeout to leave a reservation.');
        } catch (OpenPaygoIssuanceException) {
            $this->assertSame(
                OpenPaygoIssuanceReservation::STATE_UNCERTAIN,
                OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('state'),
            );
            $this->assertSame(
                'SET_TIME',
                OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('generator_operation'),
            );
            $this->assertSame(
                0,
                OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('generator_value'),
            );
        }

        try {
            $service->issue($transaction, $device, 'reset');
            $this->fail('Expected an uncertain request to remain blocked.');
        } catch (OpenPaygoIssuanceException $exception) {
            $this->assertStringContainsString('requires recovery', $exception->getMessage());
        }
    }

    public function testStoredGeneratorResultRecoversTokenPersistenceFailureWithoutRegeneration(): void {
        $device = $this->createDevice();
        $this->saveConfiguration($device, 14);
        $transaction = $this->createTransaction($device);
        $transaction->token()->create([
            'device_id' => $device->id,
            'token' => 'different-token',
            'token_type' => Token::TYPE_UNLOCK,
            'token_unit' => null,
            'token_amount' => null,
        ]);
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldReceive('generateToken')->once()->andReturn([
            'token' => 'stored-result',
            'nextCounter' => 19,
        ]);
        $service = new OpenPaygoIssuanceService($generator);

        try {
            $service->issue($transaction, $device, 'unlock');
            $this->fail('Expected the conflicting token row to fail persistence.');
        } catch (OpenPaygoIssuanceException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->assertSame(14, (new OpenPaygoDeviceConfigurationService())->getForDevice($device)['nextCounter']);
        $this->assertSame(
            OpenPaygoIssuanceReservation::STATE_GENERATED,
            OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('state'),
        );

        $transaction->token()->delete();
        $token = $service->issue($transaction, $device, 'unlock');

        $this->assertSame('stored-result', $token->token);
        $this->assertSame(19, (new OpenPaygoDeviceConfigurationService())->getForDevice($device)['nextCounter']);
    }

    public function testStaleRequestingReservationBecomesUncertainAndIsNeverRegenerated(): void {
        $device = $this->createDevice();
        $configuration = $this->saveConfiguration($device);
        $transaction = $this->createTransaction($device);
        $reservation = OpenPaygoIssuanceReservation::query()->create([
            'transaction_id' => $transaction->id,
            'device_id' => $device->id,
            'active_device_id' => $device->id,
            'operation' => 'unlock',
            'generator_operation' => 'DISABLE_PAYG',
            'counter' => $configuration->next_counter,
            'starting_code' => $configuration->starting_code,
            'secret_key_ciphertext' => $configuration->getRawOriginal('secret_key_hex'),
            'state' => OpenPaygoIssuanceReservation::STATE_REQUESTING,
        ]);
        $reservation->updated_at = now()->subMinutes(20);
        $reservation->save();
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldNotReceive('generateToken');
        $service = new OpenPaygoIssuanceService($generator);

        $this->assertSame(1, $service->recoverStaleReservations());
        $this->assertSame(
            OpenPaygoIssuanceReservation::STATE_UNCERTAIN,
            $reservation->fresh()->state,
        );

        $this->expectException(OpenPaygoIssuanceException::class);
        $service->issue($transaction, $device, 'unlock');
    }

    private function createDevice(): Device {
        return Device::query()->create([
            'person_id' => null,
            'device_type' => DeviceType::SolarHomeSystem->value,
            'device_id' => random_int(1, 100000000),
            'device_serial' => uniqid('openpaygo-issuance-', true),
        ]);
    }

    private function saveConfiguration(Device $device, int $counter = 6): OpenPaygoDeviceConfiguration {
        return (new OpenPaygoDeviceConfigurationService())->saveForDevice(
            $device,
            '0123456789abcdef0123456789abcdef',
            123,
            $counter,
        );
    }

    private function createTransaction(Device $device): Transaction {
        return Transaction::query()->create([
            'original_transaction_id' => 1,
            'original_transaction_type' => 'App\\Models\\Transaction\\CashTransaction',
            'amount' => 0,
            'type' => Transaction::TYPE_AD_HOC,
            'sender' => '',
            'message' => $device->device_serial,
        ]);
    }
}
