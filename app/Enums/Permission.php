<?php

namespace App\Enums;

/**
 * Everything a member of a business can be allowed to do. Roles map to a set of these
 * (see Role::permissions()), and the frontend receives the list to show or hide UI.
 */
enum Permission: string
{
    case ManageBusiness = 'manage_business';
    case ManageOutlets = 'manage_outlets';
    case ManageStaff = 'manage_staff';
    case InviteAgents = 'invite_agents';
    case ViewAuditLog = 'view_audit_log';
    case RecordTransactions = 'record_transactions';
    case VoidTransactions = 'void_transactions';
    case ManageChargeRules = 'manage_charge_rules';
    case ImportStatements = 'import_statements';
    case ApproveFloat = 'approve_float';
    case ApproveReconciliation = 'approve_reconciliation';
    case ReopenDay = 'reopen_day';
    case ViewReports = 'view_reports';
    case ExportReports = 'export_reports';
}
