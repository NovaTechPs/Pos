<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $guarded = [];
protected $fillable = ['name', 'display_name', 'group'];
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
