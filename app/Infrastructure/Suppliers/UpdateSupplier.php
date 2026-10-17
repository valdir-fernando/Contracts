<?php

namespace App\Infrastructure\Suppliers;

use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateSupplier
{
    public function __construct(private LockActor $actors, private SupplierRegistrationInput $input) {}

    public static function revision(object $supplier): string
    {
        return hash_hmac('sha256', json_encode([$supplier->id, $supplier->name, $supplier->document,
            $supplier->document_key, $supplier->raw_payload, $supplier->updated_at], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function save(User $expected, int $id, array $input): void
    {
        DB::transaction(function () use ($expected, $id, $input) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $supplier = DB::table('suppliers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($supplier, 404);
            try {
                $data = Validator::make($input, ['name' => 'required|string|max:255',
                    'document' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
                        if (! preg_match('/^[\d\s.\/-]+$/D', $value) || ! Normalizer::validDocument(Normalizer::document($value))) {
                            $fail('Informe um CPF/CNPJ válido.');
                        }
                    }], 'revision' => 'required|string|size:64'])->validate();
            } catch (ValidationException $error) {
                throw $error->errorBag('catalog-supplier');
            }
            if (! hash_equals(self::revision($supplier), $data['revision'])) {
                throw ValidationException::withMessages(['revision' => 'O fornecedor foi alterado. Recarregue a página antes de editar novamente.'])->errorBag('catalog-supplier');
            }
            $details = $this->input->details($input);
            $document = Normalizer::document($data['document']);
            if (DB::table('suppliers')->where('id', '<>', $id)->where('document_key', $document)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['document' => 'Fornecedor já cadastrado para este CPF/CNPJ. Consulte o catálogo.'])->errorBag('catalog-supplier');
            }
            $payload = json_decode($supplier->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            $payload = array_replace($payload, $details, ['Nome_Fornecedor' => $data['name'], 'CPF_CNPJ' => $document,
                'PF_PJ' => strlen($document) === 11 ? 'PF' : 'PJ', 'Data_Atualizacao' => now()->format('Y-m-d')]);
            $record = ['name' => $data['name'], 'name_key' => Normalizer::name($data['name']),
                'document' => $data['document'], 'document_key' => $document,
                'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now()];
            DB::table('suppliers')->where('id', $id)->update($record);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'supplier_updated',
                'metadata' => ['supplier_id' => $id, 'before_revision' => self::revision($supplier),
                    'after_revision' => self::revision(DB::table('suppliers')->where('id', $id)->first())], 'created_at' => now()]);
        }, 3);
    }
}
