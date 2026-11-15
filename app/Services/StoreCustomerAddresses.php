<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StoreCustomerAddresses
{
    /** @param array{label: string, recipient_name: string, phone: string, city: string, address_line: string, is_default: bool} $input */
    public function save(StoreCustomer $customer, array $input, ?int $id = null): StoreCustomerAddress
    {
        $input['phone'] = CustomerAccount::normalizePhone($input['phone']);
        $values = Validator::make($input, [
            'label' => ['required', 'string', 'max:50'], 'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'regex:/^[0-9]{7,15}$/'], 'city' => ['required', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:350'], 'is_default' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($customer, $id, $values): StoreCustomerAddress {
            StoreCustomer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $address = $id ? $customer->addresses()->findOrFail($id) : new StoreCustomerAddress(['tenant_id' => $customer->tenant_id, 'store_customer_id' => $customer->id]);
            $values['is_default'] = $values['is_default'] || ! $customer->addresses()->where('id', '!=', $address->id ?? 0)->where('is_default', true)->exists();
            if ($values['is_default']) {
                foreach ($customer->addresses()->where('is_default', true)->get() as $previous) {
                    $previous->update(['is_default' => false]);
                }
            }
            $address->fill($values)->save();

            return $address;
        });
    }

    public function delete(StoreCustomer $customer, int $id): void
    {
        DB::transaction(function () use ($customer, $id): void {
            StoreCustomer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $address = $customer->addresses()->findOrFail($id);
            $wasDefault = $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $customer->addresses()->oldest('id')->first()?->update(['is_default' => true]);
            }
        });
    }
}
