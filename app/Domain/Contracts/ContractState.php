<?php

namespace App\Domain\Contracts;

use DateTimeImmutable;
use DateTimeInterface;

/** Framework-free contract snapshot; partial changes retain absent/null semantics. */
final readonly class ContractState
{
    public function __construct(public ?int $id, private array $attributes, private ?array $before = null) {}

    public function get(string $field): mixed
    {
        return $this->attributes[$field] ?? null;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function original(): array
    {
        return $this->before ?? $this->attributes;
    }

    public function with(array $values): self
    {
        return new self($this->id, array_replace($this->attributes, $values), $this->original());
    }

    public function version(): int
    {
        return (int) ($this->get('version') ?? 1);
    }

    public function date(string $field): ?DateTimeImmutable
    {
        $value = $this->get($field);
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : new DateTimeImmutable($value);
    }

    public function invalidChangedEndDate(): ?string
    {
        $changed = $this->id === null;
        foreach (['vigencia_inicio', 'vigencia_fim_original', 'vigencia_fim_atual'] as $field) {
            $changed = $changed || substr($this->original()[$field] ?? '', 0, 10) !== substr($this->get($field) ?? '', 0, 10);
        }
        if ($changed) {
            foreach (['vigencia_fim_original', 'vigencia_fim_atual'] as $field) {
                if (ContractValidity::endsBeforeStart($this->date('vigencia_inicio'), $this->date($field))) {
                    return $field;
                }
            }
        }

        return null;
    }

    public function exercise(): ?int
    {
        if (preg_match('~/([0-9]{2}|[0-9]{4})$~D', trim($this->get('numero') ?? ''), $match)) {
            return strlen($match[1]) === 2 ? 2000 + (int) $match[1] : (int) $match[1];
        }

        return $this->date('data_contrato') ? (int) $this->date('data_contrato')->format('Y') : null;
    }

    public function requiresReview(): bool
    {
        foreach (['n_aditivo', 'proc_aditivo'] as $field) {
            $value = trim($this->get($field) ?? '');
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }

        return $this->id !== null && (bool) $this->get('vigencia_em_revisao');
    }
}
