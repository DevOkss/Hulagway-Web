# HULAGWAY Backend — Task Tracker

Status legend: `[ ]` pending · `[~]` in progress · `[x]` done

## Phase 1 — Foundation
- [x] Switch `.env` to MySQL (`DB_CONNECTION=mysql`, db `hulagway`, port 3309), verify connectivity
- [x] Install & configure Laravel Sanctum (migration published + run, `HasApiTokens` on User)
- [x] Register `routes/api.php` in `bootstrap/app.php`
- [x] Theme: orange gradient palette (#F97316 anchor) + DM Sans/Poppins fonts in Tailwind config
- [x] **Horizon UI restyle applied**: #F4F7FE canvas, floating white sidebar, borderless `hcard` surfaces with `shadow-horizon`, navy text (`navy`/`hgray` palettes), orange active nav, branded Welcome landing + auth screens
- [x] Port Horizon UI sidebar/navbar/card patterns to shadcn-vue components *(existing starter shell restyled via brand tokens — full port continues with Phase 3 pages)*
- [x] Placeholder logo/icon → real logo assets received (`public/images/logo.png`, `bg.png`)

## Phase 2 — Backend Domain
- [x] Migrations + models: roles, barangays, programs, surveys, survey_questions, survey_options, survey_responses (uuid), survey_answers, extension_activities, extension_activity_documents, sync_logs, **extension_activity_days, extension_activity_collaborators**
- [x] Role middleware (`role:` alias → EnsureRole) + User role helpers
- [x] Seeders: 5 roles, 3 default accounts, **55 Tangub City barangays**, 12 programs (`php artisan db:seed` ✓)
- [x] Survey module: SurveyService (create/update/publish/deactivate + question sync), SurveyResponseService (idempotent store + aggregation), SurveyController, PublicSurveyController, StoreSurveyRequest, routes ✓
- [x] Mobile auth API: `/api/auth/login|logout|user` (Sanctum tokens)
- [x] Extension activity module: CRUD, **fragmented activity days** (day_count + `days[]` date array), **multi-program collaboration** (lead-program coordinator + collaborators), status auto-computed from fragmented dates, daily document uploads with `activity_date` + `progress`, collaborators management (lead-only toggle via `POST|DELETE /extensions/{id}/collaborators`)
- [x] MapService + endpoints: `GET /api/map/community`, `GET /api/map/extensions` (GeoJSON choropleth + markers)
- [x] Dashboard API: `/api/dashboard/statistics`, `/api/dashboard/activities` (by program/over time/participants/coverage with filters)
- [x] Report exports: `/reports/activities/{pdf|xlsx}`, `/reports/surveys/{survey}/{pdf|xlsx}` + filtered Reports page endpoint (dompdf + laravel-excel; Pest-verified ✓)
- [x] Mobile REST API: `/api/mobile/surveys|surveys/{id}|barangays`, batch `POST /api/mobile/sync` — **Pest-verified idempotency ✓**

## Phase 3 — Web Frontend (Inertia/Vue, in this repo under resources/js)
- [x] Role-based sidebar nav (Officer / Coordinator / others) + HULAGWAY branding/logo
- [x] Dashboard cards + Chart.js charts (by program, over time, participants) + date filters
- [x] Survey builder UI (Create/Edit with QuestionEditor) + Index + Show w/ response aggregation charts
- [x] Leaflet choropleth map page (`/map`) + barangay panel + activity markers
- [x] Extension activities: Index w/ timeline, Create (fragmented days picker), Show (Gantt from activity days + daily schedule + documents), Edit (fragmented days), collaborators display + lead-only editor
- [x] Reports page with filters + PDF/Excel export buttons
- [x] User account management (create/edit/toggle-active/delete; coordinator = per program)
- [x] Public guest survey page (`/s/{token}`, dynamic question renderer, UUID idempotency)

## Phase 4/5 — Verification
- [x] Pest tests: sync idempotency, report exports, boundary import, profile soft-delete — **36 passed**
- [x] Pint clean; frontend `npm run build` ✓
- [x] GeoJSON boundary upload (`/boundaries`, fuzzy name matching: name/NAME_3/BgyName/ADM4_EN + alias/fuzzy fallback)
- [x] **Tangub boundaries imported**: all 55 barangays from PSA/NAMRIA open data (PSGC PH1004215xxx), also re-importable via `php artisan hulagway:import-boundaries <file>`
- [x] Role-based nav scoping (LGU → map only; field personnel → dashboard+map; coordinator/officer per module)
- [~] Remaining: on-device PWA testing, production deployment config
