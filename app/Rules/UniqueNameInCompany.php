<?php

namespace App\Rules;

use App\Support\NameNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — UniqueNameInCompany
//  Location: app/Rules/UniqueNameInCompany.php
//
//  Refuses a customer / supplier-or-employee / team-member name that
//  already exists IN THE SAME COMPANY. Another company having the
//  same name is none of this company's business and is never looked
//  at: the company id is written into the query explicitly (on top
//  of the automatic company filter the models already have), so the
//  rule is safe even if that filter is ever bypassed.
//
//  "Same" is decided by NameNormalizer::key() — spaces at the start,
//  end or doubled inside the name, and capital letters, do not make
//  a name different. Comparison is done in PHP over the company's
//  own names so it behaves identically on MySQL, PostgreSQL and
//  SQLite, and also catches OLD records that were saved with stray
//  spaces before this rule existed.
//
//  Editing: the record being edited is ignored. And if its name is
//  not being changed, the check is skipped entirely, so fixing a
//  phone number on an older record that happens to share a name with
//  another one is never blocked.
// ══════════════════════════════════════════════════════════════════
class UniqueNameInCompany implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model        Customer, Vendor or User
     * @param  string               $messageKey   errors.* translation key
     * @param  int|null             $ignoreId     the record being edited
     */
    public function __construct(
        private readonly string $model,
        private readonly ?int $companyId,
        private readonly string $messageKey,
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = NameNormalizer::key($value);

        if ($key === '' || ! $this->companyId) {
            return;
        }

        $existing = $this->model::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->get(['id', 'name']);

        if ($this->ignoreId) {
            $own = $existing->firstWhere('id', $this->ignoreId);

            // Name unchanged → nothing new to check.
            if ($own && NameNormalizer::key($own->name) === $key) {
                return;
            }
        }

        $clash = $existing->first(
            fn ($row) => (int) $row->id !== (int) $this->ignoreId
                && NameNormalizer::key($row->name) === $key
        );

        if ($clash) {
            $fail(__($this->messageKey, ['name' => NameNormalizer::clean($value)]));
        }
    }
}
