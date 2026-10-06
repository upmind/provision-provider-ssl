<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Organization identity for OV certificate orders. The customer contact is the authorized representative.
 *
 * Fields one CA requires and another does not are nullable; that CA rejects an order without them.
 *
 * @property-read string $name Registered organization name
 * @property-read string $address1 Address line 1
 * @property-read string $city City
 * @property-read string $country_code ISO alpha-2 country code
 * @property-read string|null $state State/province/region; providers that need one send `city` when empty
 * @property-read string|null $postcode Postal code
 * @property-read string|null $registration_number Company registration number
 */
class OrganizationParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'name' => ['required', 'string'],
            'address1' => ['required', 'string'],
            'city' => ['required', 'string'],
            'country_code' => ['required', 'country_code'],
            'state' => ['nullable', 'string'],
            'postcode' => ['nullable', 'string'],
            'registration_number' => ['nullable', 'string'],
        ]);
    }
}
