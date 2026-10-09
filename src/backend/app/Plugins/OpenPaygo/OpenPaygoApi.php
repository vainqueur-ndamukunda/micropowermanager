<?php

namespace App\Plugins\OpenPaygo;

use App\DTO\TransactionDataContainer;
use App\Enums\ManufacturerCapability;
use App\Exceptions\Manufacturer\ApiCallDoesNotSupportedException;
use App\Lib\IManufacturerAPI;
use App\Models\Device;
use App\Models\Token;
use App\Plugins\OpenPaygo\Exceptions\OpenPaygoCounterPersistenceException;
use App\Plugins\OpenPaygo\Http\Clients\OpenPaygoGeneratorClient;
use App\Plugins\OpenPaygo\Services\OpenPaygoDeviceConfigurationService;
use Throwable;

class OpenPaygoApi implements IManufacturerAPI {
    public function __construct(
        private OpenPaygoGeneratorClient $generatorClient,
        private OpenPaygoDeviceConfigurationService $configurationService,
    ) {}

    /**
     * @return list<ManufacturerCapability>
     */
    public function capabilities(): array {
        return [
            ManufacturerCapability::UnlockToken,
            ManufacturerCapability::ResetToken,
        ];
    }

    public function chargeDevice(TransactionDataContainer $transactionContainer): array {
        throw new ApiCallDoesNotSupportedException(
            'OpenPAYGO credit tokens require a verified mapping from MPM credit units and the device time divider.'
        );
    }

    public function unlockDevice(TransactionDataContainer $transactionContainer): array {
        $device = $transactionContainer->device;
        if ($device === null) {
            throw new ApiCallDoesNotSupportedException('OpenPAYGO unlock requires a device.');
        }

        $token = $this->generateAndPersistCounter($device, 'DISABLE_PAYG');

        return [
            'token' => $token,
            'token_type' => Token::TYPE_UNLOCK,
            'token_unit' => null,
            'token_amount' => null,
        ];
    }

    public function clearDevice(Device $device): ?array {
        $token = $this->generateAndPersistCounter($device, 'SET_TIME', 0);

        return [
            'token' => $token,
            'token_type' => Token::TYPE_RESET,
            'token_unit' => null,
            'token_amount' => null,
        ];
    }

    private function generateAndPersistCounter(Device $device, string $tokenType, ?int $value = null): string {
        $configuration = $this->configurationService->getForDevice($device);
        $payload = [
            'secretKeyHex' => $configuration['secretKeyHex'],
            'startingCode' => $configuration['startingCode'],
            'counter' => $configuration['nextCounter'],
            'tokenType' => $tokenType,
            'restrictedDigitSet' => false,
        ];

        if ($value !== null) {
            $payload['value'] = $value;
        }

        $generated = $this->generatorClient->generateToken($payload);

        try {
            $this->configurationService->updateNextCounter($device, $generated['nextCounter']);
        } catch (Throwable) {
            throw new OpenPaygoCounterPersistenceException(
                'OpenPAYGO generated a token but could not persist its counter; the token was not returned.'
            );
        }

        return $generated['token'];
    }
}
