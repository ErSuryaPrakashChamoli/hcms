# PeopleOS Phase 9 — Discovery

- **Date:** 1 October 2026.
- **Baseline:** `feature/oct_1_phase_1` at `335acd7` (Phase 8 end). The tree is clean, 6 commits are
  ahead of the pushed branch, and there are 92 migrations.
- **Scope:** Career, Talent & Succession foundation, plus statutory evidence maintenance.

## 1. What already exists

| Area | Existing | Location | Gap |
|---|---|---|---|
| Career paths | `career_paths` (code, name, job family, status) + `career_path_steps` (designation, order, `required_skills` with free-text proficiency, required competencies, typical years) | `Performance\Models\CareerPath(Step)`; Filament Career paths | No track, no organisation / business-unit scope, no versions or effective dates; skill levels are text, not Phase 8 scale levels |
| Career aspirations | `career_aspirations`: **one mutable row per employee** (`employee_id` unique), with target designation, text, interests and relocation / role-change flags | `Performance\Models\CareerAspiration` | Overwrites history; no short / medium / long term; no visibility control |
| Career Passport | `CareerPassport` service + page: person skills, certifications, finalized appraisals, aspiration, next path step, text-proficiency gaps | `Performance\Services\CareerPassport` | Reads `person_skills`, not the Phase 8 sourced skill history |
| Roles / positions | **No position-seat entity.** `Designation` (job family, level, grade, department, effective dates) is the canonical role. `employee_positions` is each employee's effective-dated assignment (designation, department, location, business unit, team…) with `change_type` (hire, transfer, promotion, demotion, reassignment, correction) | `Organisation`, `Employment` | — |
| Organisation | `OrganisationNode` tree (company, business unit, division, department, team, location) | `Organisation` | — |
| Skills, learning, development | Phase 8: versioned skill scales, sourced `EmployeeSkill` history, `SkillProfiles` (best-evidenced level per class), assessments, completions, certificates, development plans + items, `DevelopmentNeedsReader` | `Skills`, `Learning`, `Development` | Consumed read-only / through services |
| Performance | Phase 7 `PerformanceOutcomesReader` (finalized outcomes only), competencies | `Performance` | Consumed read-only |
| Relationship scope | `PerformanceRelationships` (configured types; mentor, buddy and project excluded by default) | `Performance\Services` | Reused |
| Exit dates | `exit_cases.last_working_day` | `Exit` | Read for "upcoming incumbent exit" (a fact) |
| Attrition AI | `Ai\Services\AttritionRisk` (Phase 15 blueprint) | `Ai` | **Must not be used** by career / talent / succession (no flight-risk inference) |
| Talent / succession | **None** | — | New |

## 2. Decisions

| Brief | Decision |
|---|---|
| §6–8 career profile, aspirations, goals | `career_profiles` (one per employee: preferred functions and locations, mobility preferences, target roles / job families, development priorities, **sharing flags** the employee controls for managers). `career_aspiration_entries`: effective-dated history (term, target designation / job family / location / track, text). A new entry supersedes the current one of the same term; nothing is overwritten. The legacy `career_aspirations` row is kept as a derived current snapshot for the Career Passport. `career_goals` are separate from performance goals and optionally link a skill, competency, course, path, development plan or target designation; they never create promotions, transfers or salary changes. |
| §9–11 paths, tracks, requirements | `career_tracks` (configurable: individual contributor, people manager, technical specialist, functional specialist, leadership). `career_paths` extended additively with track, business unit and organisation scope; immutable `career_path_versions` snapshot the steps. `role_requirement_versions`: effective-dated, immutable requirements per designation (optionally per organisation unit) covering required / preferred skills on a **pinned Phase 8 scale version**, competencies, experience, certifications and learning. |
| §12–13 gaps | `RoleGaps` reports facts only: for each required skill, the required level, the employee's best-evidenced level, its basis (verified / manager / self …), the gap and the scale version. Different scale versions are flagged, never compared blindly. Learning and certification status is completed / in progress / missing / expired. No score, no ranking, no enrolment. |
| §14 readiness | Configurable labels (Ready now, Ready < 1 year, Ready 1–2 years, Longer term, Not assessed). `readiness_assessments` are recorded by an authorised person with reason, evidence and effective dates, and are immutable; a new one supersedes under a row lock. |
| §15–17 succession | A **position = designation (+ optional organisation unit)**. `critical_positions` + immutable `critical_position_assessments` (criticality, business impact, scarcity, replacement difficulty, operational dependency, review frequency — configured, assessed by people). `succession_plans` per critical position; the incumbent is derived from current assignments. `succession_candidates` are unique while active, carry strengths, gaps and status, and are added or removed with a reason. Candidacy is **not** visible to the candidate by default. |
| §19–22 talent | `talent_pools` + effective-dated `talent_pool_memberships` (explicit, audited, unique while active). `talent_profiles` (career track, mobility, development priorities, encrypted confidential notes). Configurable `talent_assessment_models` (versioned dimensions; a 9-box is one possible configuration) + immutable `talent_assessments`. `talent_review_sessions` (scope, participants, population) + `talent_review_items` (decision, reason, reviewer, time); completion is locked, with an optional workflow pinned to its version. |
| §26 development actions | `talent_development_actions` link a succession candidate or review item to a **Phase 8 development-plan item** (created through `DevelopmentPlans`), so learning stays in the plan and is never assigned twice. |
| §27 mobility | `mobility_interests` (position, job family, department, location or track; active / withdrawn). No marketplace and no recruitment objects. |
| §28 movement history | Read-only from `employee_positions` (`change_type`) and lifecycle transitions; no new history table. |
| §18, §29–31 confidentiality | New permission families `career.*`, `talent.*` and `succession.*`, with succession candidacy, pool membership, talent assessments and confidential notes behind explicit permissions. Manager scope comes from `PerformanceRelationships`; organisation scope from `AccessScopes`. Confidential reads are audited. |
| §35 API | `/api/v1/career`, `/api/v1/talent`, `/api/v1/succession` with separate scopes; generic employee endpoints are unchanged. |
| §45–49 statutory | Evidence maintenance only. Official sources are retrieved again; nothing is verified or resolved by the implementer; the gate is untouched; no payroll change. |

## 3. Boundaries

- No promotion, appointment, salary, hiring, ranking, termination-risk or suitability decision.
- No opaque score and no use of `AttritionRisk` or the AI gateway.
- Performance, learning and skills records are read only (or written through their own services
  for development-plan items).
- No RMS, recruitment, candidate or requisition concepts. "Successor candidate" is a succession
  term only and never becomes a recruitment object.
