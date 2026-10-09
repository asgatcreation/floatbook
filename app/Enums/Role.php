<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Supervisor = 'supervisor';
    case Agent = 'agent';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Supervisor => 'Supervisor',
            self::Agent => 'Agent',
            self::Accountant => 'Accountant',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access to every outlet, staff, settings and reports.',
            self::Supervisor => 'Runs assigned outlets: approves float requests and closes the day.',
            self::Agent => 'Records transactions at the counter of assigned outlets.',
            self::Accountant => 'Read-only access to reports and the audit log.',
        };
    }

    /**
     * Whether this role works only within the outlets assigned to the member.
     */
    public function isOutletScoped(): bool
    {
        return $this === self::Supervisor || $this === self::Agent;
    }

    /**
     * Roles that a business can require to use two-factor authentication.
     * Agents are exempt: they use a quick PIN on shared counter devices instead.
     */
    public function canBeRequiredToUseTwoFactor(): bool
    {
        return $this !== self::Agent;
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Supervisor => [
                Permission::InviteAgents,
                Permission::RecordTransactions,
                Permission::VoidTransactions,
                Permission::ApproveFloat,
                Permission::ApproveReconciliation,
                Permission::ViewReports,
            ],
            self::Agent => [
                Permission::RecordTransactions,
            ],
            self::Accountant => [
                Permission::ViewAuditLog,
                Permission::ViewReports,
                Permission::ExportReports,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Roles that the given role may invite or assign.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => self::cases(),
            self::Supervisor => [self::Agent],
            default => [],
        };
    }
}
