<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DocumentSettings
{
    public function __construct(private LockActor $actors) {}

    public function current(): object
    {
        return DB::table('document_settings')->where('id', 1)->firstOrFail();
    }

    /** Shared header values for document templates; capture when issuing each document. */
    public function headerData(): array
    {
        $settings = $this->current();

        return ['administration' => $settings->administration_start_year.'/'.$settings->administration_end_year];
    }

    public function update(User $expected, array $input): void
    {
        DB::transaction(function () use ($expected, $input) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            try {
                $data = Validator::make($input, [
                    'administration_start_year' => 'required|integer|min:1900|max:9999',
                    'administration_end_year' => 'required|integer|min:1900|max:9999|gte:administration_start_year',
                    'settings_version' => 'required|integer|min:1',
                    'settings_reason' => 'required|string|min:10|max:1000',
                ], [], ['administration_start_year' => 'ano inicial', 'administration_end_year' => 'ano final',
                    'settings_version' => 'versão da configuração', 'settings_reason' => 'justificativa'])->validate();
            } catch (ValidationException $error) {
                throw $error->errorBag('document-settings');
            }
            if ((int) $data['settings_version'] !== (int) $settings->version) {
                throw ValidationException::withMessages(['settings_version' => 'A configuração foi alterada por outra pessoa. Recarregue a página antes de salvar.'])->errorBag('document-settings');
            }
            $before = $settings->administration_start_year.'/'.$settings->administration_end_year;
            $after = $data['administration_start_year'].'/'.$data['administration_end_year'];
            if ($before === $after) {
                return;
            }
            DB::table('document_settings')->where('id', 1)->update([
                'administration_start_year' => (int) $data['administration_start_year'],
                'administration_end_year' => (int) $data['administration_end_year'],
                'version' => $settings->version + 1, 'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'document_settings_updated',
                'metadata' => ['before' => $before, 'after' => $after, 'reason' => $data['settings_reason']], 'created_at' => now()]);
        }, 3);
    }
}
