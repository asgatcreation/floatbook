export type Permission =
    | 'manage_business'
    | 'manage_outlets'
    | 'manage_staff'
    | 'invite_agents'
    | 'view_audit_log'
    | 'record_transactions'
    | 'void_transactions'
    | 'manage_charge_rules'
    | 'import_statements'
    | 'approve_float'
    | 'approve_reconciliation'
    | 'reopen_day'
    | 'view_reports'
    | 'export_reports';

export type RoleValue = 'owner' | 'supervisor' | 'agent' | 'accountant';

export type OutletRef = {
    id: string;
    name: string;
};

export type Tenant = {
    business: {
        id: string;
        name: string;
        timezone: string;
        is_demo: boolean;
        require_two_factor: boolean;
    };
    membership: {
        id: string;
        role: RoleValue;
        role_label: string;
        has_pin: boolean;
        outlets: OutletRef[];
        permissions: Permission[];
    };
    businesses: { id: string; name: string; role: string }[];
    open_reversals: number;
};

export type RoleOption = {
    value: RoleValue;
    label: string;
    description: string;
    outlet_scoped: boolean;
};
