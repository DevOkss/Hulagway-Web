# HULAGWAY — Backend (hulagway-backend)

## Overview
Central server for the **HULAGWAY** system — a community mapping, survey, and extension activity management platform for CAES (College of Agriculture and Extension Services). Built with **Laravel 12 + Inertia v2 + Vue 3**, it serves two interfaces:

1. **Web Application** — Inertia + Vue pages for management, monitoring, visualization, and reporting.
2. **REST API** (`/api/*`) — consumed by the mobile PWA (`hulagway-pwa`) for offline-first field data collection.

## Tech Stack
| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP 8.2+) |
| SPA Bridge | Inertia.js v2 |
| Web Frontend | Vue 3 + Tailwind CSS 3 + shadcn-vue style components |
| Database | MySQL (`hulagway`, port 3309) |
| API Auth | Laravel Sanctum (token-based, for PWA) |
| Web Auth | Session-based |
| Mapping | Server-aggregated GeoJSON → Leaflet/OpenStreetMap on the client |
| Reports | PDF/Excel export libraries |

## System Roles
- **CAES Officer** — manages coordinators, builds/publishes surveys, views all data, generates reports
- **CAES Coordinator** — creates extension activities, uploads documents, monitors progress/Gantt
- **Field Extension Personnel** — collects surveys via the mobile PWA (online/offline)
- **LGU / Public Viewer** — views aggregated map data
- **Guest** — answers public survey links

## Key Architecture Decisions
- Dynamic survey schema: `surveys → survey_questions → survey_options`, responses in `survey_responses` (with client-generated `uuid` for idempotency) → `survey_answers`
- GIS aggregation happens **server-side** (Laravel MapService returns GeoJSON; browser never pulls raw rows)
- **Community Map (`/map`)** is mode-switchable: **Household Survey** (per-survey field aggregation, itemized per-answer in dialogs, Poor Family = Cat1 + Cat2) and **Extension Activities** (program-filterable choropleth + activity pins). Barangay filtering lives in an on-map searchable multi-select overlay that zooms the map to the selected barangay(s) and re-aggregates the data.
- Barangay boundaries: real NAMRIA/PSA-derived polygons for all 55 barangays imported (PSGC `PH1004215*`); re-importable via `/boundaries` upload or `php artisan hulagway:import-boundaries`
- Offline sync via batch endpoint `POST /api/mobile/sync`; duplicates rejected by `local_uuid`
- Branding: orange gradient palette anchored on `#F97316`, fonts DM Sans (body/UI) + Poppins (headings)

## Related Projects
- `../hulagway-pwa` — Vue 3 + Pinia field data collection PWA (talks to `/api/*`)
- `../reference-design` — Horizon UI admin template (visual reference only)
