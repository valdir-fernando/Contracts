<?php

namespace App\Infrastructure\Atas;

use App\Infrastructure\Persistence\TransactionGuard;
use App\Models\ActivityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** System CLI import of a private, hash-bound documentary review; no validity/financial effects. */
final class RegisterAtaAmendments
{
    public function run(string $directory, array $review, bool $apply = false): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || $review === []) {
            throw new RuntimeException('Pasta ou conferência documental inválida.');
        }
        $prepared = [];
        $identities = [];
        foreach ($review as $entry) {
            Validator::make($entry, [
                'fields' => 'required|array:ATA,Proc_ATA,ADT,Data_ADT,Proc_ADT,Tipo_ADT,INICIO,FINAL,Período',
                'fields.ATA' => ['required', 'regex:/^\d{3,}\/\d{4}$/D'],
                'fields.Proc_ATA' => 'required|string|max:64', 'fields.ADT' => 'required|integer|min:1',
                'fields.Data_ADT' => 'nullable|date_format:Y-m-d', 'fields.Proc_ADT' => 'nullable|string|max:64',
                'fields.Tipo_ADT' => 'nullable|string|max:100', 'fields.INICIO' => 'nullable|date_format:Y-m-d',
                'fields.FINAL' => 'nullable|date_format:Y-m-d', 'fields.Período' => 'nullable|string',
                'source_path' => 'required|string', 'source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
                'signatures' => 'required|array', 'signatures.contracting' => 'nullable|string',
                'signatures.contracted' => 'nullable|string', 'signatures.method' => 'required|in:digital,visual,mixed,absent',
                'signatures.page' => 'required|integer|min:1',
            ])->validate();
            $fields = array_replace(array_fill_keys(['ATA', 'Proc_ATA', 'ADT', 'Data_ADT', 'Proc_ADT', 'Tipo_ADT', 'INICIO', 'FINAL', 'Período'], null), $entry['fields']);
            $fields['ADT'] = (int) $fields['ADT'];
            if ($fields['INICIO'] !== null && $fields['FINAL'] !== null && $fields['INICIO'] > $fields['FINAL']) {
                throw new RuntimeException('Intervalo documental inválido.');
            }
            $identity = $fields['ATA'].'|'.$fields['ADT'].'|'.$fields['Proc_ATA'];
            if (isset($identities[$identity])) {
                throw new RuntimeException('Aditivo duplicado na conferência: '.$identity);
            }
            $identities[$identity] = true;
            $path = realpath($root.'/'.$entry['source_path']);
            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
                throw new RuntimeException('PDF fora da pasta de origem ou ausente.');
            }
            if ((new AtaAmendmentSourcePolicy)->isUnsigned($path)) {
                throw new RuntimeException('Documento de pasta excluída: não será cadastrado nem anexado.');
            }
            $bytes = file_get_contents($path);
            if (! str_starts_with($bytes, '%PDF-') || ! hash_equals($entry['source_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('PDF alterado após a conferência.');
            }
            $signatures = $entry['signatures'];
            $both = trim($signatures['contracting'] ?? '') !== '' && trim($signatures['contracted'] ?? '') !== '';
            if ($both && ($signatures['method'] === 'absent' || $signatures['contracting'] === $signatures['contracted'])) {
                throw new RuntimeException('Conferência das duas partes inválida.');
            }
            $prepared[] = ['fields' => $fields, 'pdf' => $both ? $bytes : null,
                'evidence' => ['source_path' => $entry['source_path'], 'source_sha256' => $entry['source_sha256'],
                    'signatures' => $signatures, 'attached' => $both]];
        }

        return DB::transaction(function () use ($prepared, $apply) {
            $result = ['apply' => $apply, 'documents' => count($prepared), 'with_both_signatures' => 0,
                'without_attachment' => 0, 'inserted' => 0, 'attached' => 0, 'existing' => 0];
            $current = DB::table('tb_ADT_ATA')->orderBy('ID')->lockForUpdate()->get();
            $changes = [];
            foreach ($prepared as ['fields' => $fields, 'pdf' => $pdf, 'evidence' => $evidence]) {
                $matches = $current->filter(fn ($row) => $row->ATA === $fields['ATA'] && (int) $row->ADT === $fields['ADT'] && $row->Proc_ATA === $fields['Proc_ATA']);
                if ($matches->count() > 1) {
                    throw new RuntimeException('Cadastro existente ambíguo: '.$fields['ATA']);
                }
                $existing = $matches->first();
                $result[$pdf === null ? 'without_attachment' : 'with_both_signatures']++;
                if ($existing !== null) {
                    foreach ($fields as $column => $value) {
                        if ($value === null ? $existing->{$column} !== null : (string) $existing->{$column} !== (string) $value) {
                            throw new RuntimeException('Cadastro existente divergente: '.$fields['ATA'].' / '.$column);
                        }
                    }
                    if ($existing->Doc_ADT !== null && $pdf !== null && ! hash_equals(hash('sha256', $existing->Doc_ADT), hash('sha256', $pdf))) {
                        throw new RuntimeException('PDF existente divergente; histórico preservado.');
                    }
                    $result['existing']++;
                    if ($existing->Doc_ADT === null && $pdf !== null) {
                        $result['attached']++;
                        if ($apply) {
                            TransactionGuard::requireActive();
                            DB::table('tb_ADT_ATA')->where('ID', $existing->ID)->update(['Doc_ADT' => $pdf]);
                            $changes[] = ['id' => $existing->ID, 'operation' => 'attach', ...$evidence];
                        }
                    }
                } else {
                    $result['inserted']++;
                    $result['attached'] += $pdf !== null ? 1 : 0;
                    if ($apply) {
                        TransactionGuard::requireActive();
                        $id = DB::table('tb_ADT_ATA')->insertGetId([...$fields, 'Doc_ADT' => $pdf], 'ID');
                        $changes[] = ['id' => $id, 'operation' => 'insert', 'fields' => $fields, ...$evidence];
                    }
                }
            }
            if ($apply && $changes !== []) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_amendments_registered',
                    'metadata' => ['source' => 'authorized_cli_documentary_review', 'changes' => $changes], 'created_at' => now()]);
            }

            return $result;
        }, 3);
    }
}
