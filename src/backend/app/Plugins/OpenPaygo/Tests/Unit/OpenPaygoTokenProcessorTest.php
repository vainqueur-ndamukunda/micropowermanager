<?php

declare(strict_types=1);

namespace App\Plugins\OpenPaygo\Tests\Unit;

use App\DTO\TransactionDataContainer;
use App\Enums\DeviceType;
use App\Events\TransactionSuccessfulEvent;
use App\Jobs\TokenProcessor;
use App\Models\Device;
use App\Models\Manufacturer;
use App\Models\Token;
use App\Models\Transaction\Transaction;
use App\Plugins\OpenPaygo\Exceptions\OpenPaygoGeneratorException;
use App\Plugins\OpenPaygo\Http\Clients\OpenPaygoGeneratorClient;
use App\Plugins\OpenPaygo\Models\OpenPaygoIssuanceReservation;
use App\Plugins\OpenPaygo\Services\OpenPaygoDeviceConfigurationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\RefreshMultipleDatabases;
use Tests\TestCase;

class OpenPaygoTokenProcessorTest extends TestCase {
    use MockeryPHPUnitIntegration;
    use RefreshMultipleDatabases;

    public function testCompletedOpenPaygoTransactionWithRecreateDoesNotCallGeneratorAgain(): void {
        $device = Device::query()->create([
            'person_id' => null,
            'device_type' => DeviceType::SolarHomeSystem->value,
            'device_id' => 1,
            'device_serial' => uniqid('openpaygo-job-', true),
        ]);
        (new OpenPaygoDeviceConfigurationService())->saveForDevice(
            $device,
            '0123456789abcdef0123456789abcdef',
            123,
            4,
        );
        $transaction = Transaction::query()->create([
            'original_transaction_id' => 1,
            'original_transaction_type' => 'App\\Models\\Transaction\\CashTransaction',
            'amount' => 0,
            'type' => Transaction::TYPE_AD_HOC,
            'sender' => '',
            'message' => $device->device_serial,
        ]);
        $container = new TransactionDataContainer();
        $container->transaction = $transaction;
        $container->device = $device;
        $container->manufacturer = new Manufacturer(['name' => 'OpenPAYGO', 'api_name' => 'OpenPaygoApi']);
        $container->appliancePerson = null;
        $container->applianceInstallmentsFullFilled = true;
        $container->paidRates = [];
        config()->set('services.openpaygo_generator.url', 'https://generator.example');
        config()->set('services.openpaygo_generator.api_key', 'test-key');
        Http::fake([
            'https://generator.example/generate' => Http::response([
                'token' => '123456789',
                'nextCounter' => 6,
            ]),
        ]);
        Event::fake([TransactionSuccessfulEvent::class]);

        (new TokenProcessor(1, $container))->executeJob();
        (new TokenProcessor(1, $container, true))->executeJob();

        Http::assertSentCount(1);
        $this->assertSame(1, Token::query()->where('transaction_id', $transaction->id)->count());
        $this->assertSame(
            OpenPaygoIssuanceReservation::STATE_COMPLETED,
            OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('state'),
        );
    }

    public function testUncertainGeneratorOutcomeDoesNotQueueAnAutomaticRetry(): void {
        $device = Device::query()->create([
            'person_id' => null,
            'device_type' => DeviceType::SolarHomeSystem->value,
            'device_id' => 1,
            'device_serial' => uniqid('openpaygo-timeout-', true),
        ]);
        (new OpenPaygoDeviceConfigurationService())->saveForDevice(
            $device,
            '0123456789abcdef0123456789abcdef',
            123,
            4,
        );
        $transaction = Transaction::query()->create([
            'original_transaction_id' => 1,
            'original_transaction_type' => 'App\\Models\\Transaction\\CashTransaction',
            'amount' => 0,
            'type' => Transaction::TYPE_AD_HOC,
            'sender' => '',
            'message' => $device->device_serial,
        ]);
        $container = new TransactionDataContainer();
        $container->transaction = $transaction;
        $container->device = $device;
        $container->manufacturer = new Manufacturer(['name' => 'OpenPAYGO', 'api_name' => 'OpenPaygoApi']);
        $container->appliancePerson = null;
        $container->applianceInstallmentsFullFilled = true;
        $container->paidRates = [];
        $generator = Mockery::mock(OpenPaygoGeneratorClient::class);
        $generator->shouldReceive('generateToken')->once()->andThrow(new OpenPaygoGeneratorException('timeout'));
        $this->app->instance(OpenPaygoGeneratorClient::class, $generator);
        Queue::fake();
        Event::fake([TransactionSuccessfulEvent::class]);

        (new TokenProcessor(1, $container))->executeJob();

        Queue::assertNothingPushed();
        $this->assertSame(
            OpenPaygoIssuanceReservation::STATE_UNCERTAIN,
            OpenPaygoIssuanceReservation::query()->where('transaction_id', $transaction->id)->value('state'),
        );
    }
}
