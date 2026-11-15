<?php

namespace App\Models;

use Database\Factories\WebCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebCategory extends Model
{
    /** @use HasFactory<WebCategoryFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'image', 'sort_order', 'is_active'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
