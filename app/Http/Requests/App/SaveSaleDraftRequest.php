<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

// ══════════════════════════════════════════════════════════════════
//  Saving an unfinished sale.
//
//  Loose ON PURPOSE: the whole point of a draft is that it can be
//  incomplete — no customer yet, a line with no price, a date not
//  decided. The real rules run later, when the draft is recorded
//  through StoreSaleRequest. What is checked here is only what
//  keeps the stored row sane: the right shape, and a sensible size.
// ══════════════════════════════════════════════════════════════════
class SaveSaleDraftRequest extends FormRequest
{
    /** The only form fields a draft keeps. */
    public const FIELDS = [
        'sale_kind', 'customer_id', 'sales_channel_id', 'date', 'lines',
        'vat_rate', 'mode', 'method', 'payment_channel_id', 'amount_now',
        'due_in_days', 'installment_count', 'installment_interval_days',
    ];

    /** Keys kept on each line. */
    public const LINE_FIELDS = ['item_id', 'qty', 'uom', 'qty_per_uom', 'base_unit_name', 'unit_price'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->company_id;
    }

    public function rules(): array
    {
        return [
            'data'         => ['required', 'array'],
            'data.lines'   => ['nullable', 'array', 'max:100'],
            'data.lines.*' => ['array'],
        ];
    }

    /**
     * The draft as it will be stored: known fields only, and every
     * value a plain scalar (a draft never needs anything nested
     * except its lines).
     *
     * @return array<string, mixed>
     */
    public function draftData(): array
    {
        $data = Arr::only((array) $this->input('data', []), self::FIELDS);

        foreach ($data as $key => $value) {
            if ($key !== 'lines' && ! is_scalar($value) && $value !== null) {
                unset($data[$key]);
            }
            if (is_string($value) && mb_strlen($value) > 200) {
                $data[$key] = mb_substr($value, 0, 200);
            }
        }

        $data['lines'] = collect($data['lines'] ?? [])
            ->map(fn ($line) => collect(Arr::only((array) $line, self::LINE_FIELDS))
                ->filter(fn ($v) => is_scalar($v) || $v === null)
                ->map(fn ($v) => is_string($v) ? mb_substr($v, 0, 100) : $v)
                ->all())
            ->values()
            ->all();

        return $data;
    }
}
