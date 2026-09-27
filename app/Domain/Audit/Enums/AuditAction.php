<?php

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    // Data
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Restore = 'RESTORE';
    case Import = 'IMPORT';
    case Export = 'EXPORT';
    case View = 'VIEW';

    // Workflow
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Escalated = 'ESCALATED';
    case Delegated = 'DELEGATED';

    // Security
    case Login = 'LOGIN';
    case Logout = 'LOGOUT';
    case LoginFailed = 'LOGIN_FAILED';
    case PasswordChanged = 'PASSWORD_CHANGED';
    case MfaChanged = 'MFA_CHANGED';
    case RoleChanged = 'ROLE_CHANGED';
    case PermissionChanged = 'PERMISSION_CHANGED';

    // Platform / configuration
    case TenantProvisioned = 'TENANT_PROVISIONED';
    case TenantSuspended = 'TENANT_SUSPENDED';
    case TenantReactivated = 'TENANT_REACTIVATED';
    case FeatureChanged = 'FEATURE_CHANGED';
    case SettingChanged = 'SETTING_CHANGED';
    case PolicyCreated = 'POLICY_CREATED';
    case PolicyUpdated = 'POLICY_UPDATED';
    case PolicyPublished = 'POLICY_PUBLISHED';
    case PolicyRetired = 'POLICY_RETIRED';
    case WorkflowChanged = 'WORKFLOW_CHANGED';
    case ShiftChanged = 'SHIFT_CHANGED';
    case FormChanged = 'FORM_CHANGED';

    // Payroll
    case PayrollStarted = 'PAYROLL_STARTED';
    case PayrollCalculated = 'PAYROLL_CALCULATED';
    case PayrollApproved = 'PAYROLL_APPROVED';
    case PayrollFinalized = 'PAYROLL_FINALIZED';
    case PayrollReopened = 'PAYROLL_REOPENED';
    case PayslipGenerated = 'PAYSLIP_GENERATED';

    // Lifecycle
    case Joined = 'JOINED';
    case Confirmed = 'CONFIRMED';
    case Promoted = 'PROMOTED';
    case Transferred = 'TRANSFERRED';
    case SalaryChanged = 'SALARY_CHANGED';
    case ManagerChanged = 'MANAGER_CHANGED';
    case ExitInitiated = 'EXIT_INITIATED';
    case ExitCompleted = 'EXIT_COMPLETED';
    case AlumniCreated = 'ALUMNI_CREATED';

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }
}
