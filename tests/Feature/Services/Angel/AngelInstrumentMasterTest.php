<?php

namespace Tests\Feature\Services\Angel;

use App\Services\Angel\AngelInstrumentMaster;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AngelInstrumentMasterTest extends TestCase
{
    public function test_instrument_master_download_uses_extended_timeout_and_finds_option(): void
    {
        config()->set('cache.default', 'array');
        Http::preventStrayRequests();

        $requestTimeout = null;
        Http::fake([
            'https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json' => function (Request $request, array $options) use (&$requestTimeout) {
                $requestTimeout = $options['timeout'];

                return Http::response([
                    [
                        'token' => '12345',
                        'symbol' => 'NIFTY15OCT2623000CE',
                        'strike' => '2300000',
                        'expiry' => '15OCT2026',
                        'lotsize' => '65',
                        'exch_seg' => 'NFO',
                        'name' => 'NIFTY',
                        'instrumenttype' => 'OPTIDX',
                    ],
                ]);
            },
        ]);

        $option = (new AngelInstrumentMaster)->findNiftyOption(
            new DateTimeImmutable('2026-10-15'),
            23000,
            'CE',
        );

        $this->assertSame(120, $requestTimeout);
        $this->assertSame([
            'token' => '12345',
            'symbol' => 'NIFTY15OCT2623000CE',
            'strike' => 23000,
            'expiry' => '2026-10-15',
            'lot_size' => 65,
        ], $option);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json');
    }
}
