<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
    // public function permissions()
    // {
    //     // نحدد اسم جدول الربط، ثم المفتاح الأجنبي لهذا النموذج (role_id)، ثم مفتاح النموذج المرتبط (permission_id)
    //     return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id');
    // }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
