<?php

namespace App\Infrastructure\Contracts;

use App\Application\Contracts\Ports\ContractCreationDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class OnlineContractCreationDate implements ContractCreationDate
{
    public const URL = 'https://timeapi.io/api/Time/current/zone';

    public const TIMEZONE = 'America/Sao_Paulo';

    public function today(): string
    {
        if (DB::transactionLevel() !== 0) {
            // Compatibility callers may already own a transaction; never perform HTTP under its locks.
            return $this->localDate();
        }
        try {
            $response = Http::acceptJson()->withHeaders(['Cache-Control' => 'no-cache'])
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()->get(self::URL, ['timeZone' => self::TIMEZONE]);
        } catch (ConnectionException) {
            return $this->localDate();
        }
        if (! $response->successful() || strlen($response->body()) > 8192) {
            return $this->localDate();
        }
        $data = $response->json();
        if (! is_array($data) || ($data['timeZone'] ?? null) !== self::TIMEZONE
            || ! is_int($data['year'] ?? null) || ! is_int($data['month'] ?? null) || ! is_int($data['day'] ?? null)
            || $data['year'] < 1000 || $data['year'] > 9999 || ! checkdate($data['month'], $data['day'], $data['year'])) {
            return $this->localDate();
        }

        return sprintf('%04d-%02d-%02d', $data['year'], $data['month'], $data['day']);
    }

    private function localDate(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d');
    }
}
