<?php

namespace App\Support\Tenancy;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Membership;

/**
 * Who may see and change whom on the Staff page.
 *
 * Owners manage everyone except themselves. Supervisors only see and manage agents
 * working entirely inside the supervisor's own outlets. Nobody manages themselves.
 */
class StaffAccess
{
    public function canViewStaff(Membership $me): bool
    {
        return $me->allows(Permission::ManageStaff) || $me->allows(Permission::InviteAgents);
    }

    public function canView(Membership $me, Membership $other): bool
    {
        if ($me->is($other) || $me->allows(Permission::ManageStaff)) {
            return true;
        }

        return $me->allows(Permission::InviteAgents)
            && $other->role === Role::Agent
            && $this->sharesAnOutlet($me, $other);
    }

    public function canManage(Membership $me, Membership $other): bool
    {
        if ($me->is($other) || $me->business_id !== $other->business_id) {
            return false;
        }

        if ($me->allows(Permission::ManageStaff)) {
            return true;
        }

        return $me->allows(Permission::InviteAgents)
            && $other->role === Role::Agent
            && $other->outlets->isNotEmpty()
            && $other->outlets->every(fn ($outlet) => $me->coversOutlet($outlet->id));
    }

    private function sharesAnOutlet(Membership $a, Membership $b): bool
    {
        return $a->outlets->pluck('id')->intersect($b->outlets->pluck('id'))->isNotEmpty();
    }
}
