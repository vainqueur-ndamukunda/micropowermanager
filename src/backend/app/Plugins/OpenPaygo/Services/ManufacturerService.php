<?php

namespace App\Plugins\OpenPaygo\Services;

use App\Models\Manufacturer;

class ManufacturerService {
    public function __construct(private Manufacturer $manufacturer) {}

    public function register(): void {
        $api = $this->manufacturer->newQuery()->where('api_name', 'OpenPaygoApi')->first();
        if ($api === null) {
            $this->manufacturer->newQuery()->create([
                'name' => 'OpenPAYGO',
                'type' => 'shs',
                'website' => 'https://enaccess.github.io/OpenPAYGO-docs/',
                'api_name' => 'OpenPaygoApi',
            ]);
        }
    }
}
