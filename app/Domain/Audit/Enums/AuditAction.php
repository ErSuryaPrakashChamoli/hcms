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

    // Phase 12: HR service delivery (requests / cases, knowledge, policy acknowledgement).
    case RequestCreated = 'REQUEST_CREATED';
    case RequestSubmitted = 'REQUEST_SUBMITTED';
    case RequestAssigned = 'REQUEST_ASSIGNED';
    case RequestReassigned = 'REQUEST_REASSIGNED';
    case RequestStatusChanged = 'REQUEST_STATUS_CHANGED';
    case RequestEscalated = 'REQUEST_ESCALATED';
    case RequestResolved = 'REQUEST_RESOLVED';
    case RequestClosed = 'REQUEST_CLOSED';
    case RequestCancelled = 'REQUEST_CANCELLED';
    case CommentCreated = 'COMMENT_CREATED';
    case AttachmentUploaded = 'ATTACHMENT_UPLOADED';
    case AttachmentDownloaded = 'ATTACHMENT_DOWNLOADED';
    case ConfidentialCaseViewed = 'CONFIDENTIAL_CASE_VIEWED';
    case KnowledgePublished = 'KNOWLEDGE_PUBLISHED';
    case PolicyAcknowledged = 'POLICY_ACKNOWLEDGED';

    // Phase 13: engagement and communication. Anonymous survey responses and anonymous feedback are
    // recorded without any actor, IP, user agent or request id (AuditRecorder::record(anonymous: true)).
    case SurveyCreated = 'SURVEY_CREATED';
    case SurveyVersionCreated = 'SURVEY_VERSION_CREATED';
    case SurveyApproved = 'SURVEY_APPROVED';
    case SurveyPublished = 'SURVEY_PUBLISHED';
    case SurveyOpened = 'SURVEY_OPENED';
    case SurveyClosed = 'SURVEY_CLOSED';
    case SurveyArchived = 'SURVEY_ARCHIVED';
    case SurveyInvitationSent = 'SURVEY_INVITATION_SENT';
    case SurveyResponseSubmitted = 'SURVEY_RESPONSE_SUBMITTED';
    case ConfidentialResponseIdentified = 'CONFIDENTIAL_RESPONSE_IDENTIFIED';
    case FeedbackSubmitted = 'FEEDBACK_SUBMITTED';
    case CampaignCreated = 'CAMPAIGN_CREATED';
    case CampaignApproved = 'CAMPAIGN_APPROVED';
    case CampaignPublished = 'CAMPAIGN_PUBLISHED';
    case CampaignScheduled = 'CAMPAIGN_SCHEDULED';
    case CampaignCancelled = 'CAMPAIGN_CANCELLED';
    case AnnouncementCreated = 'ANNOUNCEMENT_CREATED';
    case AnnouncementApproved = 'ANNOUNCEMENT_APPROVED';
    case AnnouncementPublished = 'ANNOUNCEMENT_PUBLISHED';
    case AnnouncementAcknowledged = 'ANNOUNCEMENT_ACKNOWLEDGED';
    case AudienceCreated = 'AUDIENCE_CREATED';
    case AudienceUsed = 'AUDIENCE_USED';
    case CommunicationPreferenceChanged = 'COMMUNICATION_PREFERENCE_CHANGED';
    // Phase 14: Integration Hub (inbound events, references, signing) and outbound webhook dead letters.
    // Phase 14: an AI request left PeopleOS for an external provider (counts only; never content).
    case AiExternalRequest = 'AI_EXTERNAL_REQUEST';
    // Production readiness closure: an outbound request to a tenant-configured destination was refused by the SSRF guard.
    case OutboundDestinationBlocked = 'OUTBOUND_DESTINATION_BLOCKED';
    case IntegrationSecretRotated = 'INTEGRATION_SECRET_ROTATED';
    case IntegrationSignatureRejected = 'INTEGRATION_SIGNATURE_REJECTED';
    case IntegrationEventReceived = 'INTEGRATION_EVENT_RECEIVED';
    case IntegrationEventProcessed = 'INTEGRATION_EVENT_PROCESSED';
    case IntegrationEventFailed = 'INTEGRATION_EVENT_FAILED';
    case IntegrationEventDeadLettered = 'INTEGRATION_EVENT_DEAD_LETTERED';
    case IntegrationEventReprocessed = 'INTEGRATION_EVENT_REPROCESSED';
    case ExternalReferenceLinked = 'EXTERNAL_REFERENCE_LINKED';
    case ExternalReferenceRetired = 'EXTERNAL_REFERENCE_RETIRED';
    case WebhookDeadLettered = 'WEBHOOK_DEAD_LETTERED';
    case WebhookReplayed = 'WEBHOOK_REPLAYED';
    // SaaS.2: identity lifecycle and platform operator governance. No event ever carries a token, code or secret.
    case InvitationIssued = 'INVITATION_ISSUED';
    case InvitationAccepted = 'INVITATION_ACCEPTED';
    case InvitationRevoked = 'INVITATION_REVOKED';
    case PasswordResetRequested = 'PASSWORD_RESET_REQUESTED';
    case EmailVerified = 'EMAIL_VERIFIED';
    case SessionsRevoked = 'SESSIONS_REVOKED';
    case PlatformAccessStarted = 'PLATFORM_ACCESS_STARTED';
    case PlatformAccessEnded = 'PLATFORM_ACCESS_ENDED';
    case SigningSecretRotated = 'SIGNING_SECRET_ROTATED';
    // SaaS.3: commercial entitlement configuration (never individual entitlement checks, which are observability).
    case EntitlementConfigured = 'ENTITLEMENT_CONFIGURED';
    case EntitlementSet = 'ENTITLEMENT_SET';
    case EntitlementEnded = 'ENTITLEMENT_ENDED';
    case EntitlementOverrideGranted = 'ENTITLEMENT_OVERRIDE_GRANTED';
    case EntitlementOverrideRevoked = 'ENTITLEMENT_OVERRIDE_REVOKED';
    // SaaS.4: the commercial plan catalogue (platform chain) and tenant plan assignments (tenant and platform chains).
    case PlanCreated = 'PLAN_CREATED';
    case PlanUpdated = 'PLAN_UPDATED';
    case PlanVersionDrafted = 'PLAN_VERSION_DRAFTED';
    case PlanVersionEdited = 'PLAN_VERSION_EDITED';
    case PlanVersionPublished = 'PLAN_VERSION_PUBLISHED';
    case PlanVersionRetired = 'PLAN_VERSION_RETIRED';
    case PlanAssigned = 'PLAN_ASSIGNED';
    case PlanAssignmentEnded = 'PLAN_ASSIGNMENT_ENDED';
    // SaaS.6: the commercial subscription lifecycle (tenant and platform chains; module "subscriptions").
    case TrialStarted = 'TRIAL_STARTED';
    case TrialExtended = 'TRIAL_EXTENDED';
    case TrialConverted = 'TRIAL_CONVERTED';
    case SubscriptionActivated = 'SUBSCRIPTION_ACTIVATED';
    case SubscriptionRenewed = 'SUBSCRIPTION_RENEWED';
    case GraceEntered = 'GRACE_ENTERED';
    case GraceExtended = 'GRACE_EXTENDED';
    case SubscriptionReactivated = 'SUBSCRIPTION_REACTIVATED';
    case SubscriptionExpired = 'SUBSCRIPTION_EXPIRED';
    case SubscriptionCancelled = 'SUBSCRIPTION_CANCELLED';
    case SubscriptionPlanChanged = 'SUBSCRIPTION_PLAN_CHANGED';
    // SaaS.7: billing, tax and payments (platform chain; tenant records also on the tenant chain).
    case BillingMarketCreated = 'BILLING_MARKET_CREATED';
    case BillingMarketUpdated = 'BILLING_MARKET_UPDATED';
    case PriceCreated = 'PRICE_CREATED';
    case PriceVersionDrafted = 'PRICE_VERSION_DRAFTED';
    case PriceVersionPublished = 'PRICE_VERSION_PUBLISHED';
    case PriceVersionRetired = 'PRICE_VERSION_RETIRED';
    case SupplierProfileRecorded = 'SUPPLIER_PROFILE_RECORDED';
    case InvoiceSeriesCreated = 'INVOICE_SERIES_CREATED';
    case InvoiceSeriesClosed = 'INVOICE_SERIES_CLOSED';
    case TaxRuleDrafted = 'TAX_RULE_DRAFTED';
    case TaxRuleSubmitted = 'TAX_RULE_SUBMITTED';
    case TaxRuleVerified = 'TAX_RULE_VERIFIED';
    case TaxRuleRetired = 'TAX_RULE_RETIRED';
    case BillingProfileRecorded = 'BILLING_PROFILE_RECORDED';
    case BillingTermsSet = 'BILLING_TERMS_SET';
    case BillingTermsEnded = 'BILLING_TERMS_ENDED';
    case InvoiceDrafted = 'INVOICE_DRAFTED';
    case InvoiceIssued = 'INVOICE_ISSUED';
    case InvoiceDiscarded = 'INVOICE_DISCARDED';
    case InvoicePaid = 'INVOICE_PAID';
    case PaymentInitiated = 'PAYMENT_INITIATED';
    case PaymentRecorded = 'PAYMENT_RECORDED';
    case PaymentPending = 'PAYMENT_PENDING';
    case PaymentSucceeded = 'PAYMENT_SUCCEEDED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case PaymentCancelled = 'PAYMENT_CANCELLED';
    case PaymentReconciliationException = 'PAYMENT_RECONCILIATION_EXCEPTION';
    case PaymentExceptionResolved = 'PAYMENT_EXCEPTION_RESOLVED';

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }
}
