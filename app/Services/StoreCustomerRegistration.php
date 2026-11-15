<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\StoreCustomer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StoreCustomerRegistration
{
    public function register(Branch $branch, string $name, string $phone, string $password): StoreCustomer
    {
        $attributes = Validator::make(['name' => trim($name), 'phone' => CustomerAccount::normalizePhone($phone), 'password' => $password], [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'regex:/^[0-9]{7,15}$/', Rule::unique('store_customers')->where('tenant_id', $branch->tenant_id)],
            'password' => ['required', 'string', 'max:255', Password::min(8)],
        ], ['phone.unique' => 'رقم الهاتف مسجل في هذا المتجر. سجّل الدخول.'])->validate();
        try {
            return StoreCustomer::query()->create($attributes + ['tenant_id' => $branch->tenant_id]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'رقم الهاتف مسجل في هذا المتجر. سجّل الدخول.']);
        }
    }
}
