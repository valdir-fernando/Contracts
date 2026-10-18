<?php

namespace App\Infrastructure\Managers;

use App\Models\Contract;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ManagerSignatory
{
    public const MUNICIPAL_FUND = 'MUNICIPIO DE NIQUELANDIA';

    public static function isMunicipal(string $name): bool
    {
        return Normalizer::name($name) === self::MUNICIPAL_FUND;
    }

    public function forFund(object $fund, bool $required = false): array
    {
        if (DB::transactionLevel() > 0) {
            $fund = DB::table('contract_funds')->whereNull('deleted_at')->where('id', $fund->id)->lockForUpdate()->first();
            abort_unless($fund, 422, 'O Fundo não está disponível.');
        }
        $key = Normalizer::name($fund->name);
        $municipal = self::isMunicipal($fund->name);
        $records = DB::table('tb_Gestor')->whereNull('deleted_at')->where('fund_name_key', $key)
            ->orderBy('id')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get();
        $uniqueFund = DB::table('contract_funds')->whereNull('deleted_at')->where('name_key', $key)->count() === 1;
        if (! $municipal) {
            return $uniqueFund && $records->count() === 1 ? $this->project($records->first()) : [];
        }
        abort_unless($uniqueFund, 422, 'O cadastro do Fundo municipal está ambíguo. Revise os Fundos antes de emitir documentos.');
        $active = $records->filter(fn ($record) => (string) (json_decode($record->raw_payload, true)['Assinatura'] ?? 0) === '1');
        abort_if($active->count() > 1, 422, 'Há mais de um gestor autorizado a assinar pelo Executivo. Mantenha apenas um ativo.');
        if ($active->isEmpty()) {
            $active = $records->filter(fn ($record) => in_array(Normalizer::name(json_decode($record->raw_payload, true)['Cargo'] ?? ''),
                ['PREFEITO', 'PREFEITO MUNICIPAL', 'PREFEITA', 'PREFEITA MUNICIPAL'], true));
        }
        if (! $required && $active->isEmpty()) {
            return [];
        }
        abort_unless($active->count() === 1, 422, 'Cadastre um único Prefeito no Fundo municipal ou ative um gestor para assinar pelo Executivo.');

        return $this->project($active->first());
    }

    private function project(object $record): array
    {
        $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);

        return ['Nome_Gestor' => $record->name, 'CPF_Gestor' => $raw['CPF_Gestor'] ?? null,
            'RG_Gestor' => $raw['RG_Gestor'] ?? null, 'Cargo' => $raw['Cargo'] ?? null];
    }

    public function forContract(Contract $contract): array
    {
        $fields = ['Nome_Gestor' => $contract->nome_gestor, 'CPF_Gestor' => $contract->cpf_gestor,
            'RG_Gestor' => $contract->rg_gestor, 'Cargo' => $contract->cargo_gestor];
        $fund = $contract->fund_id ? DB::table('contract_funds')->whereNull('deleted_at')->where('id', $contract->fund_id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first() : null;
        if ($fund && self::isMunicipal($fund->name)) {
            $fields = $this->forFund($fund, required: true);
        }

        return $fields;
    }

    public function fingerprint(array $fields): string
    {
        return hash_hmac('sha256', json_encode($fields, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function assertUnchanged(Contract $contract, string $fingerprint): void
    {
        try {
            $current = $this->forContract($contract);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 422) {
                throw $error;
            }
            abort(409, 'O cadastro dos responsáveis pela assinatura mudou. Revise o cadastro e gere o documento novamente.');
        }
        abort_unless(hash_equals($fingerprint, $this->fingerprint($current)), 409,
            'O responsável pela assinatura mudou durante a emissão. Gere o documento novamente.');
    }
}
