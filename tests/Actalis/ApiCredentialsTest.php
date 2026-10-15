<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Tests\Actalis;

use GuzzleHttp\HandlerStack;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use phpseclib3\Crypt\EC;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Data\Configuration;
use Upmind\ProvisionProviders\SslCertificates\Actalis\Helper\ActalisApi;

class ApiCredentialsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // DataSets parse their rules through the Validator facade; without a booted app, bind one
        $app = new Container();
        $app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $app));
        Facade::setFacadeApplication($app);
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    public function testMangledCredentialsBuildAWorkingClient(): void
    {
        [$certificate, $key] = $this->credentials();

        $api = $this->api($this->collapse($certificate), $this->collapse($key));

        $certificateFile = (string) file_get_contents($this->property($api, 'certificateFile'));
        $keyFile = (string) file_get_contents($this->property($api, 'privateKeyFile'));

        $this->assertSame($certificate, $certificateFile);
        $this->assertTrue(openssl_x509_check_private_key($certificateFile, openssl_pkey_get_private($keyFile)));
    }

    public function testMangledEncryptedKeyBuildsAWorkingClient(): void
    {
        [$certificate, $key] = $this->credentials('secret');

        $api = $this->api($certificate, $this->collapse($key), 'secret');

        $keyFile = (string) file_get_contents($this->property($api, 'privateKeyFile'));

        $this->assertNotFalse(openssl_pkey_get_private($keyFile, 'secret'));
    }

    public function testMismatchedKeyFails(): void
    {
        [$certificate] = $this->credentials();
        [, $otherKey] = $this->credentials();

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('The client private key does not match the client certificate');

        $this->api($certificate, $this->collapse($otherKey));
    }

    public function testWrongPassphraseFails(): void
    {
        [$certificate, $key] = $this->credentials('secret');

        $this->expectException(ProvisionFunctionError::class);
        $this->expectExceptionMessage('check client_private_key_passphrase');

        $this->api($certificate, $key, 'wrong');
    }

    public function testErrorsNeverIncludeKeyMaterial(): void
    {
        [$certificate] = $this->credentials();
        [, $otherKey] = $this->credentials();

        try {
            $this->api($certificate, $otherKey);
            $this->fail('Expected a mismatch error');
        } catch (ProvisionFunctionError $e) {
            $body = $this->bodyOf($otherKey);

            $this->assertStringNotContainsString(substr($body, 0, 20), $e->getMessage());
            $this->assertStringNotContainsString(substr($body, 0, 20), json_encode($e->getData()) ?: '');
        }
    }

    public function testInvalidPemFailsWithTheField(): void
    {
        [$certificate] = $this->credentials();

        try {
            $this->api($certificate, 'not a key');
            $this->fail('Expected an invalid PEM error');
        } catch (ProvisionFunctionError $e) {
            $this->assertSame('The client private key is not valid PEM: No PEM block found', $e->getMessage());
            $this->assertSame(['configuration' => 'client_private_key'], $e->getData());
        }
    }

    private function api(string $certificate, string $key, ?string $passphrase = null): ActalisApi
    {
        return new ActalisApi(Configuration::create([
            'client_certificate' => $certificate,
            'client_private_key' => $key,
            'client_private_key_passphrase' => $passphrase,
            'partner_name' => 'partner',
            'sandbox' => true,
        ], false), HandlerStack::create());
    }

    /**
     * @return string[] A self-signed certificate and its private key, both PEM
     */
    private function credentials(?string $passphrase = null): array
    {
        // phpseclib writes PKCS#8 whatever the OpenSSL version; OpenSSL 1.1 would write a legacy
        // encrypted key
        $key = EC::createKey('secp256r1');
        $keyPem = ($passphrase !== null ? $key->withPassword($passphrase) : $key)->toString('PKCS8');

        $opensslKey = openssl_pkey_get_private($keyPem, $passphrase ?? '');
        $csr = openssl_csr_new(['commonName' => 'partner.example.com'], $opensslKey, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $opensslKey, 1, ['digest_alg' => 'sha256']), $certificate);

        return [$certificate, $keyPem];
    }

    /**
     * Collapse every line break to a space, as a single-line form field does.
     */
    private function collapse(string $pem): string
    {
        return trim((string) preg_replace('/\r?\n/', ' ', $pem));
    }

    private function bodyOf(string $pem): string
    {
        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private function property(ActalisApi $api, string $name): string
    {
        $property = new ReflectionProperty(ActalisApi::class, $name);
        $property->setAccessible(true);

        return (string) $property->getValue($api);
    }
}
