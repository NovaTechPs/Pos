<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\RoleDataScope;
use App\Models\User;
use App\Models\UserBranchScope;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Which records an employee may see and change, per area and per branch.
 *
 * A job (role) says what the employee may do (create, edit, delete…); the data scope says where:
 * for each area a job sets one level for the employee's own branch and one for the other branches,
 * and an employee may override any single branch. The store owner always has every branch.
 *
 * Levels: «none» sees nothing, «view» sees and prints everything, «own» works only on the records
 * the employee created, «full» works on every record of the branch.
 */
class DataScope
{
    public const NONE = 'none';

    public const VIEW = 'view';

    public const OWN = 'own';

    public const FULL = 'full';

    public const LEVELS = [
        self::NONE => 'لا شيء',
        self::VIEW => 'عرض فقط',
        self::OWN => 'ما أنشأه فقط',
        self::FULL => 'كامل',
    ];

    /**
     * Areas, their label and the levels they accept. Reports only show data, so they are seen or not.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const AREAS = [
        'sales' => ['فواتير المبيعات', [self::NONE, self::VIEW, self::OWN, self::FULL]],
        'pos' => ['فواتير نقطة البيع', [self::NONE, self::VIEW, self::OWN, self::FULL]],
        'returns' => ['المردودات', [self::NONE, self::VIEW, self::OWN, self::FULL]],
        'purchases' => ['فواتير المشتريات', [self::NONE, self::VIEW, self::OWN, self::FULL]],
        'receipts' => ['سندات القبض', [self::NONE, self::VIEW, self::OWN, self::FULL]],
        'payments' => ['سندات الصرف', [self::NONE, self::VIEW, self::OWN, self::FULL]],
        'reports' => ['التقارير', [self::NONE, self::VIEW]],
        'statement' => ['كشف حساب الزبائن والموردين', [self::NONE, self::VIEW]],
    ];

    /**
     * Without a saved scope, a job keeps the behaviour from before scopes existed: an employee works on
     * the sales and POS invoices they created, on every return and purchase of their branch, on the vouchers
     * they wrote, sees reports of their branch and statements of every branch.
     *
     * @var array<string, array{own: string, other: string}>
     */
    public const DEFAULTS = [
        'sales' => ['own' => self::OWN, 'other' => self::NONE],
        'pos' => ['own' => self::OWN, 'other' => self::NONE],
        'returns' => ['own' => self::FULL, 'other' => self::NONE],
        'purchases' => ['own' => self::FULL, 'other' => self::NONE],
        // Vouchers: the ones the employee wrote, in any branch.
        'receipts' => ['own' => self::OWN, 'other' => self::OWN],
        'payments' => ['own' => self::OWN, 'other' => self::OWN],
        'reports' => ['own' => self::VIEW, 'other' => self::NONE],
        'statement' => ['own' => self::VIEW, 'other' => self::VIEW],
    ];

    public static function unrestricted(?User $user): bool
    {
        return ! $user || $user->isTenantOwner() || $user->isSaaSAdmin();
    }

    /**
     * The job's default for an area.
     *
     * @return array{own: string, other: string}
     */
    public function roleDefault(?int $roleId, string $area): array
    {
        $saved = $roleId
            ? RoleDataScope::query()->where('role_id', $roleId)->where('area', $area)->first()
            : null;

        return $saved
            ? ['own' => $saved->own_branch, 'other' => $saved->other_branches]
            : self::DEFAULTS[$area];
    }

    /**
     * The employee's level in every branch of the store for one area.
     *
     * @return array<int, string> branch id => level
     */
    public function branchLevels(User $user, string $area): array
    {
        $branchIds = Branch::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->orderBy('id')->pluck('id');
        if (self::unrestricted($user)) {
            return $branchIds->mapWithKeys(fn ($id): array => [(int) $id => self::FULL])->all();
        }

        $default = $this->roleDefault($user->role_id ? (int) $user->role_id : null, $area);
        $overrides = UserBranchScope::query()->where('user_id', $user->id)->where('area', $area)
            ->pluck('level', 'branch_id');
        $levels = [];
        foreach ($branchIds as $branchId) {
            $levels[(int) $branchId] = $overrides[(int) $branchId]
                ?? ($this->isOwnBranch($user, (int) $branchId) ? $default['own'] : $default['other']);
        }

        return $levels;
    }

    /** An employee without an assigned branch is not tied to one: every branch counts as theirs. */
    private function isOwnBranch(User $user, ?int $branchId): bool
    {
        return ! $user->branch_id || ($branchId !== null && $branchId === (int) $user->branch_id);
    }

    /** The level for records that have no branch: they follow the employee's own branch. */
    private function ownBranchLevel(User $user, string $area, array $levels): string
    {
        return $user->branch_id
            ? ($levels[(int) $user->branch_id] ?? self::NONE)
            : $this->roleDefault($user->role_id ? (int) $user->role_id : null, $area)['own'];
    }

    public function level(User $user, string $area, ?int $branchId): string
    {
        if (self::unrestricted($user)) {
            return self::FULL;
        }
        $levels = $this->branchLevels($user, $area);

        return $branchId === null
            ? $this->ownBranchLevel($user, $area, $levels)
            : ($levels[$branchId] ?? self::NONE);
    }

    /**
     * Branches where the employee sees anything in this area; null means every branch (no restriction).
     *
     * @return list<int>|null
     */
    public function visibleBranchIds(User $user, string $area): ?array
    {
        if (self::unrestricted($user)) {
            return null;
        }
        $levels = $this->branchLevels($user, $area);
        $visible = array_keys(array_filter($levels, fn (string $level): bool => $level !== self::NONE));

        return count($visible) === count($levels) && $levels !== [] && ! in_array(self::OWN, $levels, true)
            ? null
            : $visible;
    }

    /** Branches where the employee may create records in this area (own or full). @return list<int> */
    public function creatableBranchIds(User $user, string $area): array
    {
        return array_keys(array_filter(
            $this->branchLevels($user, $area),
            fn (string $level): bool => in_array($level, [self::OWN, self::FULL], true),
        ));
    }

    /**
     * Limits a query to the records the employee may see: «view» and «full» branches whole, «own» branches
     * only for the records they created, nothing elsewhere.
     */
    public function apply(Builder $query, ?User $user, string $area, string $branchColumn = 'branch_id', string $creatorColumn = 'created_by'): Builder
    {
        if (self::unrestricted($user)) {
            return $query;
        }
        $levels = $this->branchLevels($user, $area);
        $whole = array_keys(array_filter($levels, fn (string $level): bool => in_array($level, [self::VIEW, self::FULL], true)));
        $own = array_keys(array_filter($levels, fn (string $level): bool => $level === self::OWN));
        $ownBranchLevel = $this->ownBranchLevel($user, $area, $levels);

        return $query->where(function (Builder $visible) use ($whole, $own, $ownBranchLevel, $branchColumn, $creatorColumn, $user): void {
            $visible->whereRaw('1 = 0');
            if ($whole !== []) {
                $visible->orWhereIn($branchColumn, $whole);
            }
            if ($own !== []) {
                $visible->orWhere(fn (Builder $mine) => $mine->whereIn($branchColumn, $own)->where($creatorColumn, $user->id));
            }
            // Records without a branch follow the employee's own branch.
            if (in_array($ownBranchLevel, [self::VIEW, self::FULL], true)) {
                $visible->orWhereNull($branchColumn);
            } elseif ($ownBranchLevel === self::OWN) {
                $visible->orWhere(fn (Builder $mine) => $mine->whereNull($branchColumn)->where($creatorColumn, $user->id));
            }
        });
    }

    public function canView(?User $user, string $area, ?int $branchId, ?int $createdBy): bool
    {
        if (self::unrestricted($user)) {
            return true;
        }
        $level = $this->level($user, $area, $branchId);

        return in_array($level, [self::VIEW, self::FULL], true)
            || ($level === self::OWN && (int) $createdBy === (int) $user->id);
    }

    /** Editing, deleting and adding payments to an existing record. */
    public function canChange(?User $user, string $area, ?int $branchId, ?int $createdBy): bool
    {
        if (self::unrestricted($user)) {
            return true;
        }
        $level = $this->level($user, $area, $branchId);

        return $level === self::FULL || ($level === self::OWN && (int) $createdBy === (int) $user->id);
    }

    /** A short reason to show when the scope refuses a record. */
    public static function refusal(string $area): string
    {
        return 'لا تملك صلاحية على '.self::AREAS[$area][0].' في هذا الفرع أو لهذه الفاتورة.';
    }
}
