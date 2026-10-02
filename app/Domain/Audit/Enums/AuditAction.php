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
    case Download = 'DOWNLOAD';
    case Archive = 'ARCHIVE';
    case Assign = 'ASSIGN';
    case StatusChange = 'STATUS_CHANGE';
    case BulkOperation = 'BULK_OPERATION';

    // Workflow
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Escalated = 'ESCALATED';
    case Delegated = 'DELEGATED';
    // Phase 11: the review, scheduling, effective-date and correction steps of a controlled change.
    case Reviewed = 'REVIEWED';
    case Scheduled = 'SCHEDULED';
    case Effected = 'EFFECTED';
    case Corrected = 'CORRECTED';

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

    // Attendance (Phase 2)
    case PunchReceived = 'PUNCH_RECEIVED';
    case PunchImported = 'PUNCH_IMPORTED';
    case PunchReprocessed = 'PUNCH_REPROCESSED';
    case AttendanceCalculated = 'ATTENDANCE_CALCULATED';
    case AttendanceAdjusted = 'ATTENDANCE_ADJUSTED';
    case RegularisationRequested = 'REGULARISATION_REQUESTED';
    case RegularisationApproved = 'REGULARISATION_APPROVED';
    case RegularisationRejected = 'REGULARISATION_REJECTED';
    case ShiftAssigned = 'SHIFT_ASSIGNED';
    case ScheduleAssigned = 'SCHEDULE_ASSIGNED';
    case OvertimeApproved = 'OVERTIME_APPROVED';
    case OvertimeRejected = 'OVERTIME_REJECTED';

    // Leave (Phase 3)
    case LeaveRequested = 'LEAVE_REQUESTED';
    case LeaveApproved = 'LEAVE_APPROVED';
    case LeaveRejected = 'LEAVE_REJECTED';
    case LeaveCancelRequested = 'LEAVE_CANCEL_REQUESTED';
    case LeaveCancelled = 'LEAVE_CANCELLED';
    case LeaveBalanceAdjusted = 'LEAVE_BALANCE_ADJUSTED';
    case LeaveAccrued = 'LEAVE_ACCRUED';
    case LeaveExpired = 'LEAVE_EXPIRED';
    case LeaveCarriedForward = 'LEAVE_CARRIED_FORWARD';
    case FormChanged = 'FORM_CHANGED';

    // Compliance (Phase 5)
    case StatutoryRuleCreated = 'STATUTORY_RULE_CREATED';
    case StatutoryRuleReviewed = 'STATUTORY_RULE_REVIEWED';
    case StatutoryRuleVerified = 'STATUTORY_RULE_VERIFIED';
    case StatutoryRuleRejected = 'STATUTORY_RULE_REJECTED';
    case StatutoryRuleSuperseded = 'STATUTORY_RULE_SUPERSEDED';
    case StatutoryRegistrationCreated = 'STATUTORY_REGISTRATION_CREATED';
    case StatutoryRegistrationUpdated = 'STATUTORY_REGISTRATION_UPDATED';
    case StatutoryOutputCreated = 'STATUTORY_OUTPUT_CREATED';
    case StatutoryOutputValidated = 'STATUTORY_OUTPUT_VALIDATED';
    case StatutoryOutputApproved = 'STATUTORY_OUTPUT_APPROVED';
    case StatutoryOutputExported = 'STATUTORY_OUTPUT_EXPORTED';
    case StatutoryOutputSubmitted = 'STATUTORY_OUTPUT_SUBMITTED';
    case StatutoryOutputAcknowledged = 'STATUTORY_OUTPUT_ACKNOWLEDGED';
    case StatutoryOutputRevised = 'STATUTORY_OUTPUT_REVISED';
    case StatutoryOutputReconciled = 'STATUTORY_OUTPUT_RECONCILED';
    case StatutoryOutputCancelled = 'STATUTORY_OUTPUT_CANCELLED';
    case StatutoryOutputAccessed = 'STATUTORY_OUTPUT_ACCESSED';
    case EstablishmentAssigned = 'ESTABLISHMENT_ASSIGNED';

    // Statutory readiness (Phase 6)
    case StatutoryRuleEvidenceAttached = 'STATUTORY_RULE_EVIDENCE_ATTACHED';
    case StatutoryRuleNoticeRecorded = 'STATUTORY_RULE_NOTICE_RECORDED';
    case StatutoryRuleNoticeResolved = 'STATUTORY_RULE_NOTICE_RESOLVED';
    case ExportLayoutSubmitted = 'EXPORT_LAYOUT_SUBMITTED';
    case ExportLayoutVerified = 'EXPORT_LAYOUT_VERIFIED';
    case ExportLayoutRejected = 'EXPORT_LAYOUT_REJECTED';
    case LegalEntityVerified = 'LEGAL_ENTITY_VERIFIED';
    case EstablishmentVerified = 'ESTABLISHMENT_VERIFIED';
    case RegistrationVerified = 'REGISTRATION_VERIFIED';
    case VerificationSubmitted = 'VERIFICATION_SUBMITTED';
    case StatutoryPortalValidationRecorded = 'STATUTORY_PORTAL_VALIDATION_RECORDED';
    case ParallelRunImported = 'PARALLEL_RUN_IMPORTED';
    case ParallelDifferenceReviewed = 'PARALLEL_DIFFERENCE_REVIEWED';
    case ParallelRunReconciled = 'PARALLEL_RUN_RECONCILED';

    // Payroll
    case PayrollStarted = 'PAYROLL_STARTED';
    case PayrollCalculated = 'PAYROLL_CALCULATED';
    case PayrollApproved = 'PAYROLL_APPROVED';
    case PayrollFinalized = 'PAYROLL_FINALIZED';
    case PayrollReopened = 'PAYROLL_REOPENED';
    case PayslipGenerated = 'PAYSLIP_GENERATED';
    case PayrollAdjusted = 'PAYROLL_ADJUSTED';
    case PayrollAdjustmentApproved = 'PAYROLL_ADJUSTMENT_APPROVED';
    case PayslipAccessed = 'PAYSLIP_ACCESSED';

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
