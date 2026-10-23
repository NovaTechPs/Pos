<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait FiltersVouchers
{
    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public string $filterMethod = '';

    public string $filterSource = '';

    public string $filterMinAmount = '';

    public string $filterMaxAmount = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['filterDateFrom', 'filterDateTo', 'filterMethod', 'filterSource', 'filterMinAmount', 'filterMaxAmount'], true)) {
            $this->resetPage();
            unset($this->items);
        }
    }

    public function clearVoucherFilters(): void
    {
        $this->reset('tableSearch', 'filterDateFrom', 'filterDateTo', 'filterMethod', 'filterSource', 'filterMinAmount', 'filterMaxAmount');
        $this->resetPage();
        unset($this->items);
    }

    protected function applyVoucherFilters(Builder $query): Builder
    {
        foreach (['filterDateFrom' => '>=', 'filterDateTo' => '<='] as $property => $operator) {
            if ($this->{$property} !== '' && validator(['date' => $this->{$property}], ['date' => 'date_format:Y-m-d'])->passes()) {
                $query->whereDate('payment_date', $operator, $this->{$property});
            }
        }
        if (in_array($this->filterMethod, ['cash', 'bank', 'card', 'check', 'transfer'], true)) {
            $query->where('payment_method', $this->filterMethod);
        }
        if ($this->filterSource === 'invoice') {
            $query->where(function (Builder $source): void {
                $source->whereNotNull('order_id')->orWhereNotNull('purchase_id');
            });
        } elseif ($this->filterSource === 'manual') {
            $query->whereNull('order_id')->whereNull('purchase_id');
        }
        foreach (['filterMinAmount' => '>=', 'filterMaxAmount' => '<='] as $property => $operator) {
            if (is_numeric($this->{$property}) && (float) $this->{$property} >= 0) {
                $query->where('amount', $operator, $this->{$property});
            }
        }

        return $query;
    }
}
