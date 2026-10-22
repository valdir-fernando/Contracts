<?php

namespace App\Application\Contracts;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Contracts\Ports\ContractCatalog;
use App\Application\Contracts\Ports\ContractCreationDate;
use App\Application\Contracts\Ports\ContractInputValidator;
use App\Application\Contracts\Ports\ContractNumbering;
use App\Application\Contracts\Ports\ContractStore;
use App\Application\Exceptions\AccessDenied;
use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\InputRejected;
use App\Application\Ports\TransactionManager;
use App\Application\Security\ActorIdentity;
use App\Domain\Contracts\ContractState;
use App\Domain\Shared\BrazilianDocument;

final class SaveContract
{
    public function __construct(
        private TransactionManager $transactions,
        private ContractAccess $access,
        private ContractInputValidator $inputValidator,
        private ContractCatalog $catalog,
        private ContractStore $store,
        private ContractNumbering $numbering,
        private ContractCreationDate $creationDate,
    ) {}

    public function execute(ActorIdentity $actor, array $input, ?int $targetId = null): ContractState
    {
        $creationDate = $targetId === null ? $this->creationDate->today() : null;

        return $this->transactions->run(function () use ($actor, $input, $targetId, $creationDate) {
            $context = $this->access->lockForSave($actor, $targetId);
            $validated = $this->inputValidator->validate($context, $input);
            $data = $validated->values;
            $contract = $context->contract;
            if ($targetId !== null && (int) $data['version'] !== $contract->version()) {
                throw new ConcurrencyConflict('O contrato foi alterado por outra pessoa. Recarregue os dados antes de salvar.');
            }
            $fields = $validated->fields;
            unset($fields['data_cont_verd']);
            $contract = $contract->with($fields);
            if ($targetId === null) {
                $contract = $contract->with(['data_cont_verd' => $creationDate]);
            }
            $fundId = $data['fund_id'] ?? $contract->get('fund_id');
            $departmentId = array_key_exists('department_id', $data) ? $data['department_id'] : $contract->get('department_id');
            $fundId = $fundId === null ? null : (int) $fundId;
            $departmentId = $departmentId === null ? null : (int) $departmentId;
            if (! $this->access->canAssign($context, $fundId, $departmentId)) {
                throw new AccessDenied;
            }
            if ($targetId !== null && $context->contract->get('number_sequence') !== null && $fundId !== (int) $context->contract->get('fund_id')) {
                throw new InputRejected(['fund_id' => 'O Fundo de um contrato com numeração automática não pode ser alterado.']);
            }
            $ownershipChanged = array_key_exists('fund_id', $data) && (int) $fundId !== (int) $contract->get('fund_id')
                || array_key_exists('department_id', $data) && (int) $departmentId !== (int) $contract->get('department_id');
            if ($targetId === null || $ownershipChanged || (! $contract->get('ownership_resolved') && ! empty($data['fund_id']))) {
                if (! $fundId) {
                    throw new InputRejected(['fund_id' => 'Selecione um fundo.']);
                }
                $contract = $contract->with($this->catalog->ownership($fundId, $departmentId));
            }
            if ($invalidEnd = $contract->invalidChangedEndDate()) {
                throw new InputRejected([$invalidEnd => 'O término não pode ser anterior ao início da vigência.']);
            }
            $document = BrazilianDocument::document($contract->get('fornecedor_documento_snapshot'));
            $contract = $contract->with([
                'supplier_document_key' => strlen($document) <= 32 ? ($document ?: null) : null,
                'supplier_id' => array_key_exists('supplier_id', $validated->fields) ? $validated->fields['supplier_id']
                    : (BrazilianDocument::validDocument($document) ? $this->catalog->supplierId($document) : null),
                'exercicio' => $contract->exercise(),
            ]);
            $duplicateConfirmed = false;
            if ($targetId === null) {
                $contract = $contract->with($this->numbering->nextNumber($context, (int) $fundId)->attributes());
                $duplicate = $this->store->hasDuplicate($context, $contract);
                if ($duplicate && ! ($data['duplicate_confirmation'] ?? false)) {
                    throw new InputRejected(['duplicate_confirmation' => 'Possível duplicidade: existe um contrato acessível com o mesmo número, fundo, exercício e processo. Confira os dados e confirme para cadastrar outro registro.']);
                }
                $duplicateConfirmed = $duplicate;
            }
            $contract = $contract->with([
                'vigencia_em_revisao' => $contract->requiresReview(),
                'quality_issues' => $contract->get('quality_issues') ?? [],
                'created_by' => $contract->get('created_by') ?? ($targetId === null ? $context->actor->id : null),
                'updated_by' => $context->actor->id,
                'version' => $targetId === null ? 1 : $contract->version() + 1,
            ]);

            return $this->store->save($context, $contract, $data['reason'] ?? null, $duplicateConfirmed);
        });
    }
}
