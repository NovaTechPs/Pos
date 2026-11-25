<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's own data scope in one branch for one area, overriding the job's default.
 *
 * @property int $branch_id
 * @property string $area
 * @property string $level
 */
class UserBranchScope extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'branch_id', 'area', 'level'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
