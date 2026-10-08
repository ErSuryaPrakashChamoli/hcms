<?php

namespace App\Domain\Experience\Contracts;

/**
 * Phase 12 — minimum hook only. Phase 13 (Engagement & Surveys) binds an implementation that surfaces
 * survey tasks; until then the null provider contributes nothing. No survey tables, scoring or
 * analytics exist in Phase 12.
 */
interface SurveyTaskProvider extends ExperienceTaskSource {}
