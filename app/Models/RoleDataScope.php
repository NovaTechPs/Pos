<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A job's default data scope for one area: one level for the employee's own branch, one for the others.
 *
 * @property string $area
 * @property string $own_branch
 * @property string $other_branches
 */
class RoleDataScope extends Model
{
    protected $fillable = ['tenant_id', 'role_id', 'area', 'own_branch', 'other_branches'];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
