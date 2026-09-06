<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\exception\PropertyException;
use winterBootTests\Support\StubPropertySource;
use winterBootTests\Support\TestCase;

final class PropertyExpressionTest extends TestCase {

    private function basicContext(): WinterPropertyContext {
        StubPropertySource::$datasets = [
            'env' => [
                'dbUser' => 'envUser',
                'sslEnabled' => false,
                'retries' => 0,
                'zeroStr' => '0',
                'emptyKey' => '',
            ],
            'ini' => [
                'dbUser' => 'iniUser',
                'dbPass' => 'iniPass',
                'poolSize' => '25',
            ],
            'vault' => [
                'dbPass' => 'vaultPass',
                'sslEnabled' => true,
                'apiKey' => 'vault-key-123',
                'nullKey' => null,
            ],
        ];
        return new WinterPropertyContext([__DIR__ . '/fixtures/basic']);
    }

    public function testFirstMatchWins(): void {
        $ctx = $this->basicContext();
        $this->assertSame('envUser', $ctx->get('datasource.username'));
        $this->assertSame('iniPass', $ctx->get('datasource.password'));
    }

    public function testSkipsMissingKeys(): void {
        $ctx = $this->basicContext();
        $this->assertSame('iniUser', $ctx->get('datasource.adminUser'));
        $this->assertSame('vaultPass', $ctx->get('datasource.vaultPass'));
    }

    public function testFalseIsPresent(): void {
        $ctx = $this->basicContext();
        $this->assertSame(false, $ctx->get('datasource.sslEnabled'));
        $this->assertFalse($ctx->getBool('datasource.sslEnabled'));
    }

    public function testZeroIsPresent(): void {
        $ctx = $this->basicContext();
        $this->assertSame(0, $ctx->get('datasource.retries'));
        $this->assertSame('0', $ctx->get('datasource.zeroString'));
    }

    public function testZeroLiteralDefault(): void {
        $ctx = $this->basicContext();
        $this->assertSame(0, $ctx->get('datasource.zeroDefault'));
        $this->assertSame(0, $ctx->getInt('datasource.zeroDefault'));
    }

    public function testEmptyStringFallsThrough(): void {
        $ctx = $this->basicContext();
        $this->assertSame('iniUser', $ctx->get('datasource.emptyFallsThrough'));
    }

    public function testNullFallsThrough(): void {
        $ctx = $this->basicContext();
        $this->assertSame('dflt', $ctx->get('datasource.nullFallsThrough'));
    }

    public function testUnknownSourceSkippedInChain(): void {
        $ctx = $this->basicContext();
        $this->assertSame('appuser', $ctx->get('datasource.unknownSource'));
    }

    public function testIntFloatBoolLiterals(): void {
        $ctx = $this->basicContext();
        $this->assertSame(10, $ctx->get('datasource.timeout'));
        $this->assertSame(10.5, $ctx->get('datasource.ratio'));
        $this->assertSame(false, $ctx->get('datasource.debug'));
        $this->assertSame(true, $ctx->get('datasource.flag'));
        $this->assertSame(10, $ctx->getInt('datasource.timeout'));
        $this->assertSame('25', $ctx->get('datasource.poolSize'));
    }

    public function testQuotedStringLiteral(): void {
        $ctx = $this->basicContext();
        $this->assertSame('hello world', $ctx->get('datasource.label'));
        $this->assertSame('double quoted', $ctx->get('datasource.quoted'));
    }

    public function testBareWordDefault(): void {
        $ctx = $this->basicContext();
        $this->assertSame('appuser', $ctx->get('datasource.bare'));
        $this->assertSame('envUser', $ctx->getStr('datasource.username'));
    }

    public function testExplicitNull(): void {
        $ctx = $this->basicContext();
        $this->assertNull($ctx->get('datasource.explicitNull'));
    }

    public function testSingleRefLegacyPath(): void {
        $ctx = $this->basicContext();
        $this->assertSame('vault-key-123', $ctx->get('datasource.directRef'));
    }

    public function testLegacyUnknownSourceLeftAsIs(): void {
        $ctx = $this->basicContext();
        $this->assertSame('$nosuch.key', $ctx->get('datasource.legacyUnknown'));
    }

    public function testPlainStringsUntouched(): void {
        $ctx = $this->basicContext();
        $this->assertSame('just a plain value', $ctx->get('datasource.plainString'));
        $this->assertSame('a || b', $ctx->get('datasource.pipeString'));
    }

    public function testUnresolvableChainThrows(): void {
        StubPropertySource::$datasets = ['env' => [], 'ini' => []];
        $this->assertThrows(PropertyException::class, function () {
            new WinterPropertyContext([__DIR__ . '/fixtures/unresolvable']);
        });
    }

    public function testUnquotedChains(): void {
        $ctx = $this->basicContext();
        $this->assertSame('envUser', $ctx->get('datasource.unquotedChain'));
        $this->assertSame(false, $ctx->get('datasource.unquotedBool'));
        $this->assertSame(10, $ctx->get('datasource.unquotedInt'));
    }

    public function testNativeYamlScalarsKeepTypes(): void {
        $ctx = $this->basicContext();
        $this->assertSame(0, $ctx->get('native.count'));
        $this->assertSame(false, $ctx->get('native.enabled'));
        $this->assertNull($ctx->get('native.nothing'));
        $this->assertSame('appuser', $ctx->get('native.name'));
        $this->assertSame(0, $ctx->getInt('native.count'));
        $this->assertFalse($ctx->getBool('native.enabled'));
    }

    public function testLegacyMissingKeyThrows(): void {
        StubPropertySource::$datasets = ['env' => []];
        $this->assertThrows(PropertyException::class, function () {
            new WinterPropertyContext([__DIR__ . '/fixtures/legacy-missing']);
        });
    }
}
