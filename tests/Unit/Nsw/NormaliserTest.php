<?php

namespace Tests\Unit\Nsw;

use App\Sources\Nsw\ApplicationMapper;
use App\Sources\Nsw\BuilderNameClassifier;
use App\Sources\Nsw\Feed;
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

    public function test_lot_section_field_keeps_parcels_distinct(): void
    {
        $this->assertSame('44/4/DP235700', Normaliser::lotKey('44', 'DP235700', '4'));
        $this->assertNotSame(Normaliser::lotKey('44', 'DP235700', '4'), Normaliser::lotKey('44', 'DP235700', '7'));
        $this->assertSame('44/DP235700', Normaliser::lotKey('44', 'DP235700', 'null'));

        $a = ApplicationMapper::toAttributes(Feed::DA, [
            'PlanningPortalApplicationNumber' => 'PAN-1',
            'Location' => [['Lot' => [['Lot' => '44', 'Section' => '4', 'PlanLabel' => 'DP235700']]]],
        ]);
        $this->assertSame(['44/4/DP235700'], $a['lot_keys']);
    }

    /** Expected keys come from addr_key() in spike/q4_linkrate.py, so the two agree. */
    #[DataProvider('spikeAddressKeys')]
    public function test_address_key_matches_the_spike(string $address, string $expected): void
    {
        $this->assertSame($expected, Normaliser::addressKey($address));
    }

    public static function spikeAddressKeys(): array
    {
        return [
            ['57 Station Road, SEVEN HILLS NSW 2147', '57 STATION ROAD SEVEN HILLS NSW 2147'],
            ['5 OAK CR KELLYVILLE', '5 OAK CRESCENT KELLYVILLE'],
            ['1 Smith Av, MENAI', '1 SMITH AVENUE MENAI'],
            ['12/34-36 George St, Parramatta NSW 2150', '12 34 36 GEORGE STREET PARRAMATTA NSW 2150'],
        ];
    }

    public function test_address_key_abbreviations_converge(): void
    {
        $this->assertSame(Normaliser::addressKey('5 OAK CR KELLYVILLE'), Normaliser::addressKey('5 Oak Crescent, Kellyville'));
        $this->assertSame(Normaliser::addressKey('1 SMITH AV MENAI'), Normaliser::addressKey('1 SMITH AVE MENAI'));
        $this->assertNull(Normaliser::addressKey('null'));
    }

    #[DataProvider('builderNames')]
    public function test_builder_classification(string $name, bool $company): void
    {
        $this->assertSame($company, BuilderNameClassifier::isCompany($name), $name);
    }

    public static function builderNames(): array
    {
        return [
            ['Hutchinson Builders Pty Ltd', true],
            ['MULTIPLEX CONSTRUCTIONS PTY. LIMITED', true],
            ['Lendlease Building Ltd', true],
            ['ACME BUILDING PTY LTD ATF ACME UNIT TRUST', true],
            ['Smith Holdings Pty Ltd', true],
            ['John Smith', false],
            ['J & M SMITH', false],
            ['Owner Builder', false],
            ['', false],
            // Individuals behind trusts and business names stay suppressed.
            ['JOHN SMITH ATF SMITH FAMILY TRUST', false],
            ['John Smith as trustee for the Smith Family Trust', false],
            ['J SMITH TRUSTEE', false],
            ['SAMUEL GROSSER T/A GROSSER BUILDING GROUP', false],
            ['J SMITH T/AS ACME CONSTRUCTIONS PTY LTD', false],
            ['SMITH FAMILY TRUST', false],
            ['GROSSER BUILDING GROUP', false],
            ['SMITH HOLDINGS', false],
        ];
    }

    public function test_builder_keys_merge_suffix_variants(): void
    {
        $this->assertSame(
            BuilderNameClassifier::key('Acme Constructions Pty. Limited'),
            BuilderNameClassifier::key('ACME CONSTRUCTIONS PTY LTD'),
        );
    }

    public function test_out_of_range_values_are_nulled_and_strings_truncated(): void
    {
        $a = ApplicationMapper::toAttributes(Feed::CC, [
            'PlanningPortalApplicationNumber' => 'PAN-1',
            'StoreysProposed' => 40000,
            'UnitsProposed' => 3000000000,
            'ProposedGrossFloorArea' => 12345678901,
            'CostOfDevelopment' => '1,250,000',
            'ApplicationStatus' => str_repeat('x', 400),
            'Location' => [['FullAddress' => str_repeat('1 LONG STREET ', 30), 'Suburb' => 'null', 'X' => 'null']],
        ]);

        $this->assertNull($a['storeys']);
        $this->assertNull($a['units']);
        $this->assertNull($a['gross_floor_area']);
        $this->assertSame(1250000.0, $a['cost']);
        $this->assertSame(255, mb_strlen($a['status']));
        $this->assertSame(255, mb_strlen($a['address_key']));
        $this->assertNull($a['suburb']);
        $this->assertNull($a['lng']);
    }

    public function test_source_updated_at_is_converted_to_utc(): void
    {
        $a = ApplicationMapper::toAttributes(Feed::CC, ['DateLastUpdated' => '2022-11-19T19:18:15.467']);

        // 19:18 AEDT (UTC+11) is 08:18 UTC.
        $this->assertSame('2022-11-19T08:18:15+00:00', $a['source_updated_at']->toIso8601String());
    }

    public function test_dwellings_and_modification_parent(): void
    {
        $a = ApplicationMapper::toAttributes(Feed::DA, [
            'NumberOfNewDwellings' => '4',
            'ModificationApplicationNumber' => 'PAN-99',
        ]);

        $this->assertSame(4, $a['units']);
        $this->assertSame('PAN-99', $a['parent_reference']);
    }
}
