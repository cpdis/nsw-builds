<?php

namespace Tests\Unit\Nsw;

use App\Sources\Nsw\BuilderNameClassifier;
use App\Sources\Nsw\Normaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NormaliserTest extends TestCase
{
    public function test_dates_in_both_formats(): void
    {
        $this->assertSame('2023-03-07', Normaliser::date('07/03/2023')->toDateString());
        $this->assertSame('2023-03-07', Normaliser::date('2023-03-07T00:00:00')->toDateString());
        $this->assertNull(Normaliser::date(''));
        $this->assertNull(Normaliser::date('31/02/2023'));
    }

    public function test_lot_keys(): void
    {
        $this->assertSame('12/DP123456', Normaliser::lotKey('Lot 12', 'DP 123456'));
        $this->assertSame('12/DP123456', Normaliser::lotKey('12', 'dp123456'));
        $this->assertSame('3/2/DP758000', Normaliser::lotKey('3 Sec 2', 'DP758000'));
        $this->assertSame('CP/SP12345', Normaliser::lotKey('CP', 'SP12345'));
        $this->assertNull(Normaliser::lotKey('12', ''));
        $this->assertNull(Normaliser::lotKey('12', 'UNKNOWN'));
    }

    public function test_address_keys(): void
    {
        $this->assertSame(
            Normaliser::addressKey('57 Station Road, SEVEN HILLS NSW 2147'),
            Normaliser::addressKey('57 STATION RD SEVEN HILLS'),
        );
    }

    #[DataProvider('builderNames')]
    public function test_builder_classification(string $name, bool $company): void
    {
        $this->assertSame($company, BuilderNameClassifier::isCompany($name));
    }

    public static function builderNames(): array
    {
        return [
            ['Hutchinson Builders Pty Ltd', true],
            ['MULTIPLEX CONSTRUCTIONS PTY. LIMITED', true],
            ['Lendlease Building Ltd', true],
            ['John Smith', false],
            ['J & M SMITH', false],
            ['Owner Builder', false],
            ['', false],
        ];
    }

    public function test_builder_keys_merge_suffix_variants(): void
    {
        $this->assertSame(
            BuilderNameClassifier::key('Acme Constructions Pty. Limited'),
            BuilderNameClassifier::key('ACME CONSTRUCTIONS PTY LTD'),
        );
    }
}
