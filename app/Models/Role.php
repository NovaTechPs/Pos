<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToTenant;
protected $fillable = ['tenant_id', 'name', 'description'];
    protected $guarded = [];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }


    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
