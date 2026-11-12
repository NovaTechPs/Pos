<?php

namespace App\Concerns;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lets a voucher page browse saved vouchers like the invoice archive: previous, next and lookup by number.
 * Employees move only through the vouchers they created; the store owner moves through all of them.
 */
trait NavigatesVoucherArchive
{
    public string $voucherArchiveSearch = '';

    abstract protected function getTenantId(): ?int;

    abstract public function edit(int $id): void;

    abstract public function openCreate(): void;

    /** The voucher type this page manages: receipt or payment. */
    abstract protected function voucherArchiveType(): string;

    /** @return Builder<Payment> */
    private function voucherArchiveQuery(): Builder
    {
        return Payment::query()->visibleTo(auth()->user())
            ->where('tenant_id', $this->getTenantId())
            ->where('type', $this->voucherArchiveType())
            // Return refunds are changed from their return document, so the archive skips them.
            ->whereNull('purchase_return_id')
            ->where(fn (Builder $query) => $query->whereNull('order_id')
                ->orWhereHas('order', fn (Builder $order) => $order->where('type', '!=', 'return')));
    }

    public function previousVoucher(): void
    {
        $previous = $this->voucherArchiveQuery()
            ->when($this->paymentId, fn (Builder $query) => $query->where('id', '<', $this->paymentId))
            ->latest('id')->first();
        if (! $previous) {
            $this->dispatch('toast', type: 'info', message: 'لا يوجد سند أقدم.');

            return;
        }
        $this->edit($previous->id);
    }

    public function nextVoucher(): void
    {
        if (! $this->paymentId) {
            return;
        }
        $next = $this->voucherArchiveQuery()->where('id', '>', $this->paymentId)->oldest('id')->first();
        if (! $next) {
            // Past the newest voucher, start a new one like the invoice archive does.
            $this->openCreate();

            return;
        }
        $this->edit($next->id);
    }

    public function searchVoucherArchive(): void
    {
        $digits = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
        $search = trim(strtr($this->voucherArchiveSearch, $digits));
        if ($search === '' || mb_strlen($search) > 100) {
            return;
        }
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $voucher = $this->voucherArchiveQuery()
            ->where(fn (Builder $query) => $query->where('voucher_number', $search)
                ->orWhereRaw("voucher_number LIKE ? ESCAPE '!'", ['%'.$escaped.'%']))
            ->orderByRaw('CASE WHEN voucher_number = ? THEN 0 ELSE 1 END', [$search])
            ->latest('id')->first();
        if (! $voucher) {
            $this->dispatch('toast', type: 'error', message: 'لم يتم العثور على سند مطابق: '.$search);

            return;
        }
        $this->voucherArchiveSearch = '';
        $this->edit($voucher->id);
    }
}
