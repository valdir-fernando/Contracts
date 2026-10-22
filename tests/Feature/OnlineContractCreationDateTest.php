<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\OnlineContractCreationDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnlineContractCreationDateTest extends TestCase
{
    public function test_online_brasilia_date_is_independent_of_local_clock_and_does_not_send_contract_data(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(OnlineContractCreationDate::TIMEZONE, $request['timeZone']);
            $this->assertSame(['timeZone' => OnlineContractCreationDate::TIMEZONE], $request->data());

            return Http::response(['year' => 2027, 'month' => 2, 'day' => 10, 'timeZone' => OnlineContractCreationDate::TIMEZONE]);
        });
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23T01:30:00+00:00'));
        try {
            $this->assertSame('2027-02-10', (new OnlineContractCreationDate)->today());
        } finally {
            CarbonImmutable::setTestNow();
        }
        Http::assertSentCount(1);
    }

    public function test_outer_transaction_uses_system_date_without_network(): void
    {
        Http::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23T01:30:00+00:00'));
        DB::beginTransaction();
        try {
            $this->assertSame('2026-10-22', (new OnlineContractCreationDate)->today());
        } finally {
            DB::rollBack();
            CarbonImmutable::setTestNow();
        }
        Http::assertNothingSent();
    }

    public static function unavailableSources(): array
    {
        return [
            'server error' => [503, 'unavailable'],
            'invalid json' => [200, '{invalid'],
            'wrong timezone' => [200, ['year' => 2027, 'month' => 2, 'day' => 10, 'timeZone' => 'UTC']],
            'invalid date' => [200, ['year' => 2027, 'month' => 2, 'day' => 30, 'timeZone' => 'America/Sao_Paulo']],
            'missing date' => [200, []],
            'redirect' => [302, 'redirect'],
        ];
    }

    #[DataProvider('unavailableSources')]
    public function test_unavailable_or_invalid_source_silently_uses_system_date_in_brasilia(int $status, array|string $body): void
    {
        Http::preventStrayRequests();
        Http::fake([OnlineContractCreationDate::URL.'*' => Http::response($body, $status)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23T01:30:00+00:00'));
        try {
            $this->assertSame('2026-10-22', (new OnlineContractCreationDate)->today());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_connection_failure_silently_uses_system_date(): void
    {
        Http::fake([OnlineContractCreationDate::URL.'*' => Http::failedConnection()]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23T01:30:00+00:00'));
        try {
            $this->assertSame('2026-10-22', (new OnlineContractCreationDate)->today());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
