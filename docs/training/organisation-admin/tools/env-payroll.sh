# The payroll clone: a copy of the training database used only to photograph the end of a payroll run (approve, close,
# publish payslips, bank file). PeopleOS refuses those steps while statutory rule versions are unverified; production
# always enforces that. This non-production server switches enforcement off so the steps can be shown.
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"
export DB_DATABASE=hcm_orgadmin_payroll_showcase
export PEOPLEOS_ENFORCE_VERIFIED_RULES=false
export TRAINING_BASE_URL=http://127.0.0.1:8092
