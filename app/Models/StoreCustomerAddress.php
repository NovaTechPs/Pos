<?php

namespace App\Models;

use Database\Factories\StoreCustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreCustomerAddress extends Model
{
    /** @use HasFactory<StoreCustomerAddressFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'store_customer_id', 'label', 'recipient_name', 'phone', 'city', 'address_line', 'is_default'];

    protected $attributes = ['is_default' => false];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function deliveryAddress(): string
    {
        return $this->city.' — '.$this->address_line;
    }
}
