<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates;

use Upmind\ProvisionBase\Laravel\ProvisionServiceProvider;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Provider as Actalis;
use Upmind\ProvisionProviders\SslCertificates\Category as SslCertificates;
use Upmind\ProvisionProviders\SslCertificates\Example\Provider as Example;
use Upmind\ProvisionProviders\SslCertificates\Sectigo\Provider as Sectigo;

class LaravelServiceProvider extends ProvisionServiceProvider
{
    public function boot()
    {
        $this->bindCategory('ssl-certificates', SslCertificates::class);

        // $this->bindProvider('ssl-certificates', 'example', Example::class);

        $this->bindProvider('ssl-certificates', 'sectigo', Sectigo::class);
        $this->bindProvider('ssl-certificates', 'actalis', Actalis::class);
    }
}
