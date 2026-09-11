<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { computed, onMounted, ref, watch } from 'vue';

interface SurveyQuestion {
    id: number;
    survey_id: number;
    question_text: string;
    type: string;
    code: string | null;
    data_scope: string;
    map_enabled: boolean;
    options?: Array<{ id: number; label: string }>;
}

interface Sdg { id: number; number: number; code: string; title: string; short_title: string; color: string; icon_url: string }
interface Props {
    community: { features: Array<any> };
    extensions: { choropleth: any; markers: { features: Array<{ geometry: any; properties: Record<string, any> }> } };
    surveys: Array<{ id: number; title: string; questions?: SurveyQuestion[] }>;
    barangays: Array<{ id: number; name: string }>;
    programs: Array<{ id: number; name: string }>;
    sdgs: Sdg[];
    extensionYears: number[];
}

const props = defineProps<Props>();
const breadcrumbs: BreadcrumbItemType[] = [{ title: 'Community Map', href: '/map' }];

const mapEl = ref<HTMLElement | null>(null);
let mapInstance: L.Map | null = null;
let geoLayer: L.GeoJSON | null = null;
let markersLayer: L.LayerGroup | null = null;
let activityLayer: L.LayerGroup | null = null;

const mapMode = ref<'survey' | 'extension'>('survey');
const selectedSurvey = ref<string>('');
const selectedProgram = ref<string>('');
const selectedFields = ref<string[]>([]);
const selectedBarangays = ref<number[]>([]);
const pendingBarangays = ref<number[]>([]);
const selectedSdgs = ref<number[]>([]);
const sdgFilterOpen = ref(false);
const selectedStatuses = ref<string[]>([]);
const selectedYear = ref<string>('');
const barangaySearch = ref('');
const barangayFilterOpen = ref(false);

const showAggDialog = ref(false);
const aggBarangay = ref<any>(null);
const aggDetail = ref<any>(null);
const aggLoading = ref(false);
const showAllDialog = ref(false);
const allDetail = ref<any>(null);
const allLoading = ref(false);
const openAllIds = ref<number[]>([]);

const showExtDialog = ref(false);
const extDetail = ref<any>(null);
const extLoading = ref(false);
const extOpenIds = ref<number[]>([]);

const selected = ref<any>(null);
const highlightedBarangayIds = ref<number[]>([]);
const currentFeatures = ref<any[]>([]);
const currentActivityMarkers = ref<any[]>([]);
const mapLoading = ref(false);
let fitPending = false;

const isSurveyMode = computed(() => mapMode.value === 'survey');
const hasSurvey = computed(() => isSurveyMode.value && selectedSurvey.value !== '');
const activityShown = computed(() => currentActivityMarkers.value.length);

const aggTotals = computed(() => {
    let households = 0;
    let poor = 0;
    currentFeatures.value.forEach((f: any) => {
        const props = f.properties ?? {};
        households += Number(props.total_households ?? 0);
        poor += Number(props.breakdown?.['Poor Family Category - Cat2'] ?? props.hulagway?.poor_cat2 ?? 0);
    });
    return { households, poor, count: currentFeatures.value.length };
});

const extTotals = computed(() => {
    let total = 0;
    currentFeatures.value.forEach((f: any) => {
        total += Number(f.properties?.value ?? 0);
    });
    if (extCityWide.value) total += extCityWide.value;
    // Prefer server total_count if available (ensures city-wide included + filters)
    if (extTotalCount.value !== null) total = extTotalCount.value;
    return { total, count: currentFeatures.value.length };
});
const extCityWide = ref(0);
const extProgramTotals = ref<Array<{ program_id: number; program_name: string; count: number }>>([]);
const extTotalCount = ref<number | null>(null);

const highLow = computed(() => {
    if (!currentFeatures.value.length) return null;
    const sorted = [...currentFeatures.value].sort((a: any, b: any) => (b.properties?.value ?? 0) - (a.properties?.value ?? 0));
    return {
        highest: { name: sorted[0].properties.name, value: sorted[0].properties.value ?? 0 },
        lowest: { name: sorted[sorted.length - 1].properties.name, value: sorted[sorted.length - 1].properties.value ?? 0 },
    };
});

const allTotals = computed(() => {
    const bars = allDetail.value?.barangays ?? [];
    let households = 0;
    let poor = 0;
    bars.forEach((b: any) => {
        households += Number(b.households ?? 0);
        const poorField = (b.fields ?? []).find((f: any) => f.key === 'field_poor_cat2' || f.label === 'Poor Family');
        poor += Number(poorField?.total ?? 0);
    });
    return { households, poor, count: bars.length };
});

const availableFields = ref<Array<{ value: string; label: string; group: string }>>([]);

const filteredBarangays = computed(() => {
    const q = barangaySearch.value.trim().toLowerCase();
    if (!q) return props.barangays;
    return props.barangays.filter((b) => b.name.toLowerCase().includes(q));
});

const isAllBarangays = computed(() => selectedBarangays.value.length === 0);

const toggleBarangay = (id: number) => {
    const idx = pendingBarangays.value.indexOf(id);
    if (idx >= 0) pendingBarangays.value.splice(idx, 1);
    else pendingBarangays.value.push(id);
};
const clearBarangayFilter = () => {
    pendingBarangays.value = [];
    selectedBarangays.value = [];
    barangaySearch.value = '';
};
const applyBarangayFilter = () => {
    selectedBarangays.value = [...pendingBarangays.value];
    barangayFilterOpen.value = false;
};
watch(barangayFilterOpen, (open) => {
    if (open) pendingBarangays.value = [...selectedBarangays.value];
});

const fetchFields = async () => {
    if (!selectedSurvey.value) {
        availableFields.value = [];
        selectedFields.value = [];
        return;
    }
    const found = (props.surveys as any[]).find((s: any) => String(s.id) === String(selectedSurvey.value));
    const qs: SurveyQuestion[] = (found as any)?.questions ?? [];
    const fields: Array<{ value: string; label: string; group: string }> = [];
    const seen = new Set<string>();

    const extra: Array<{ value: string; label: string; group: string }> = [
        { value: 'field_poor_cat2', label: 'Poor Family', group: 'Household Conditions' },
        { value: 'field_pwd', label: 'PWD (Persons)', group: 'Per-Member' },
        { value: 'field_mentally', label: 'Mentally Challenged', group: 'Per-Member' },
        { value: 'field_bedridden', label: 'Bedridden', group: 'Per-Member' },
        { value: 'field_seniors_60', label: 'Senior 60+', group: 'Per-Member' },
        { value: 'field_seniors_90', label: 'Senior 90+', group: 'Per-Member' },
        { value: 'field_osy', label: 'OSY', group: 'Per-Member' },
        { value: 'field_pregnant', label: 'Pregnant', group: 'Per-Member' },
        { value: 'field_live_in', label: 'Live-in Households', group: 'Household Conditions' },
        { value: 'field_total_households', label: 'Total Households', group: 'Aggregate' },
    ];

    const reserved = new Set(extra.map((f) => f.value.replace('field_', '')));
    ['pwd_cat2', 'mentally_cat2', 'bedridden_cat2', 'seniors_90', 'total_households', 'live_in'].forEach((c) => reserved.add(c));

    qs.forEach((q) => {
        if (q.map_enabled === false) return;
        if (seen.has(`q_${q.id}`)) return;
        if (q.code && reserved.has(q.code)) return;
        seen.add(`q_${q.id}`);
        const group = q.data_scope === 'individual' ? 'Per-Member' : q.data_scope === 'household' ? 'Household Conditions' : 'Other';
        const label = q.question_text + (q.code ? ` (${q.code})` : ` (${q.type})`);
        fields.push({ value: `q_${q.id}`, label, group });
    });

    extra.forEach((f) => {
        if (!seen.has(f.value)) {
            seen.add(f.value);
            fields.push(f);
        }
    });

    availableFields.value = fields;
    selectedFields.value = fields.some((f) => f.value === 'field_total_households') ? ['field_total_households'] : [];
    void fetchFieldTotals();
};

const fieldTotals = ref<Record<string, number>>({});

const fetchFieldTotals = async () => {
    if (!selectedSurvey.value || !isSurveyMode.value) {
        fieldTotals.value = {};
        return;
    }
    const params = new URLSearchParams();
    params.set('survey_id', selectedSurvey.value);
    availableFields.value.forEach((f) => {
        if (f.value.startsWith('q_')) params.append('question_ids[]', f.value.replace('q_', ''));
        else if (f.value.startsWith('field_')) params.append('field_codes[]', f.value.replace('field_', ''));
        else params.append('field_codes[]', f.value);
    });
    if (selectedBarangays.value.length) {
        selectedBarangays.value.forEach((id) => params.append('barangay_ids[]', String(id)));
    }
    try {
        const res = await fetch(`/api/map/aggregation?${params.toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        const map: Record<string, number> = {};
        (data.barangays ?? []).forEach((b: any) => {
            (b.fields ?? []).forEach((f: any) => {
                map[f.key] = (map[f.key] ?? 0) + Number(f.total ?? 0);
            });
        });
        fieldTotals.value = map;
    } catch {
        // no-op
    }
};

const colorFor = (value: number, max: number) => {
    if (!max) return '#FFF7ED';
    const ratio = value / max;
    if (ratio === 0) return '#FFEDD5';
    if (ratio < 0.25) return '#FED7AA';
    if (ratio < 0.5) return '#FDBA74';
    if (ratio < 0.75) return '#FB923C';
    if (ratio < 1) return '#F97316';
    return '#EA580C';
};
const highlightStyle = (feature: any, max: number, isSelected: boolean) => ({
    fillColor: colorFor(feature?.properties?.value ?? 0, max),
    fillOpacity: isSelected ? 0.9 : 0.75,
    color: isSelected ? '#EA580C' : '#ffffff',
    weight: isSelected ? 3 : 1.5,
});

let fetchSeq = 0;
const fetchActive = async () => {
    if (isSurveyMode.value) {
        await fetchSurvey();
    } else {
        await fetchExtensions();
    }
};

const fetchSurvey = async () => {
    const seq = ++fetchSeq;
    if (!selectedSurvey.value) {
        currentFeatures.value = [];
        updateMap(true);
        return;
    }
    mapLoading.value = true;
    try {
        const params = new URLSearchParams();
        params.set('survey_id', selectedSurvey.value);
        const qIds: string[] = [];
        const fCodes: string[] = [];
        selectedFields.value.forEach((v) => {
            if (v.startsWith('q_')) qIds.push(v.replace('q_', ''));
            else if (v.startsWith('field_')) fCodes.push(v.replace('field_', ''));
            else fCodes.push(v);
        });
        qIds.forEach((id) => params.append('question_ids[]', id));
        fCodes.forEach((c) => params.append('field_codes[]', c));
        if (selectedBarangays.value.length) {
            selectedBarangays.value.forEach((id) => params.append('barangay_ids[]', String(id)));
        }
        const url = `/api/map/community?${params.toString()}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (seq !== fetchSeq) return;
        if (!res.ok) return;
        const fc = await res.json();
        const doFit = fitPending;
        fitPending = false;
        currentFeatures.value = fc.features ?? [];
        currentActivityMarkers.value = [];
        updateMap(doFit);
    } finally {
        if (seq === fetchSeq) mapLoading.value = false;
    }
};

const toggleSdg = (id: number) => {
    const idx = selectedSdgs.value.indexOf(id);
    if (idx >= 0) selectedSdgs.value.splice(idx, 1);
    else selectedSdgs.value.push(id);
};
const clearSdgFilter = () => { selectedSdgs.value = []; };
const isAllSdgs = computed(() => props.sdgs.length > 0 && selectedSdgs.value.length === props.sdgs.length);
const toggleSelectAllSdg = () => {
    if (isAllSdgs.value) selectedSdgs.value = [];
    else selectedSdgs.value = props.sdgs.map((s) => s.id);
};

const fetchExtensions = async () => {
    const seq = ++fetchSeq;
    mapLoading.value = true;
    try {
        const params = new URLSearchParams();
        if (selectedProgram.value) params.set('program_id', selectedProgram.value);
        if (selectedStatuses.value.length) {
            selectedStatuses.value.forEach((s) => params.append('status[]', s));
        }
        if (selectedYear.value) params.set('year', selectedYear.value);
        if (selectedBarangays.value.length) {
            selectedBarangays.value.forEach((id) => params.append('barangay_ids[]', String(id)));
        }
        if (selectedSdgs.value.length) {
            selectedSdgs.value.forEach((id) => params.append('sdg_ids[]', String(id)));
        }
        const url = `/api/map/extensions?${params.toString()}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (seq !== fetchSeq) return;
        if (!res.ok) return;
        const data = await res.json();
        const doFit = fitPending;
        fitPending = false;
        currentFeatures.value = data.choropleth?.features ?? [];
        currentActivityMarkers.value = data.markers?.features ?? [];
        extCityWide.value = Number(data.city_wide_count ?? 0);
        extProgramTotals.value = data.program_totals ?? [];
        extTotalCount.value = data.total_count !== undefined ? Number(data.total_count) : null;
        updateMap(doFit);
    } finally {
        if (seq === fetchSeq) mapLoading.value = false;
    }
};

const renderActivityPins = () => {
    if (!activityLayer) return;
    activityLayer.clearLayers();
    // If viewing "All barangays" (no specific barangay selected), do not put markers but still count (per requirement)
    if (isAllBarangays.value) return;
    if (!isSurveyMode.value && currentActivityMarkers.value.length) {
        L.geoJSON({ type: 'FeatureCollection', features: currentActivityMarkers.value } as any, {
            pointToLayer: (feature: any, latlng: L.LatLngExpression) => {
                const sdgs: any[] = feature.properties?.sdgs ?? [];
                let html = '';
                let size: [number, number] = [30, 30];
                let anchor: [number, number] = [15, 15];
                if (sdgs.length === 0) {
                    html = `<div style="width:16px;height:16px;border-radius:50%;background:#EA580C;border:2.5px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>`;
                    size = [16, 16]; anchor = [8, 8];
                } else if (sdgs.length === 1) {
                    const s = sdgs[0];
                    html = `<div style="width:30px;height:30px;border-radius:6px;overflow:hidden;border:2px solid ${s.color};box-shadow:0 2px 6px rgba(0,0,0,.4);background:#fff"><img src="${s.icon_url}" style="width:100%;height:100%;object-fit:cover" alt="SDG ${s.number}"></div>`;
                } else {
                    const first = sdgs[0];
                    const extra = sdgs.length - 1;
                    html = `<div style="display:flex;align-items:center;gap:0;filter:drop-shadow(0 1px 3px rgba(0,0,0,.4))"><div style="width:28px;height:28px;border-radius:6px;overflow:hidden;border:2px solid ${first.color};background:#fff;flex-shrink:0"><img src="${first.icon_url}" style="width:100%;height:100%;object-fit:cover" alt="SDG ${first.number}"></div><div style="margin-left:-6px;background:${first.color};color:#fff;font-size:10px;font-weight:800;padding:1px 5px;border-radius:9999px;border:2px solid #fff;white-space:nowrap">+${extra}</div></div>`;
                    size = [52, 28]; anchor = [26, 14];
                }
                const icon = L.divIcon({ className: '', html, iconSize: size, iconAnchor: anchor });
                return L.marker(latlng, { icon });
            },
            onEachFeature: (feature: any, layer: L.Layer) => {
                const p = feature.properties;
                const sdgs: any[] = p.sdgs ?? [];
                const sdgHtml = sdgs.length ? `<div style="display:flex;flex-wrap:wrap;gap:4px;margin:6px 0">${sdgs.map((s: any) => `<span title="${s.title}" style="display:inline-flex;align-items:center;gap:3px;border:1px solid ${s.color};background:${s.color}14;color:${s.color};border-radius:6px;padding:1px 4px;font-size:10px;font-weight:700"><img src="${s.icon_url}" style="width:14px;height:14px;border-radius:2px;object-fit:cover">SDG ${s.number}</span>`).join('')}</div>` : '<div style="font-size:11px;color:#999">No SDG</div>';
                layer.bindPopup(`<strong>${p.title}</strong><br><span style="font-size:12px;color:#666">${p.program ?? ''} · ${p.barangay ?? ''}</span>${sdgHtml}<span style="font-size:12px">Status: ${p.status} · ${p.progress}%</span><br><span style="font-size:11px;color:#888">${p.location ?? ''}</span>`);
            },
        }).addTo(activityLayer);
    }
};

const updateMap = (fit = false) => {
    if (!mapInstance || !geoLayer) return;
    const values = currentFeatures.value.map((f: any) => f.properties?.value ?? 0);
    const max = Math.max(...values, 0);
    geoLayer.clearLayers();

    let featuresToShow = currentFeatures.value;
    if (selectedBarangays.value.length) {
        featuresToShow = currentFeatures.value.filter((f: any) => selectedBarangays.value.includes(Number(f.id) || Number(f.properties?.id)));
    }
    const fc = { type: 'FeatureCollection', features: featuresToShow } as any;
    geoLayer.addData(fc);
    geoLayer.eachLayer((layer: any) => {
        const feat = layer.feature;
        const props = feat.properties ?? {};
        if (isSurveyMode.value) {
            const poorVal = props.breakdown?.['Poor Family Category - Cat2'] ?? props.hulagway?.poor_cat2 ?? 0;
            layer.bindTooltip(`${props.name}: ${props.value} responses<br>No source of income (category 2): ${poorVal}`, { sticky: true });
        } else {
            const sdgBreakdown: any[] = props.sdg_breakdown ?? [];
            const programBreakdown: any[] = props.program_breakdown ?? [];
            const extensions: any[] = props.extensions ?? [];
            const progHtml = programBreakdown.length
                ? `<div style="margin-top:6px;font-size:11px;color:#444"><strong style="font-size:10px;letter-spacing:0.04em;text-transform:uppercase;color:#888">Programs</strong><div style="margin-top:3px;display:flex;flex-wrap:wrap;gap:4px">${programBreakdown.map((p:any)=>`<span style="border:1px solid #e5e7eb;background:#f9fafb;border-radius:9999px;padding:2px 6px;font-size:10px"><b>${p.program_name}</b>: ${p.count} · ${p.avg_progress}% avg</span>`).join('')}</div></div>`
                : '';
            const extListHtml = extensions.length
                ? `<div style="margin-top:6px"><strong style="font-size:10px;letter-spacing:0.04em;text-transform:uppercase;color:#888">Extensions (${extensions.length})</strong>${extensions.slice(0,3).map((e:any)=>`<div style="margin-top:4px;line-height:1.2"><div style="font-size:11px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px">${e.title}</div><div style="font-size:10px;color:#71717a">${e.program ?? '—'} · <span style="text-transform:capitalize;font-weight:600;${e.status==='ongoing'?'color:#d97706':e.status==='completed'?'color:#16a34a':'color:#71717a'}">${e.status}</span> · ${e.progress}%</div><div style="margin-top:2px;height:4px;background:#e4e4e7;border-radius:9999px;overflow:hidden"><div style="height:100%;width:${e.progress}%;background:${e.status==='completed'?'#22c55e':e.status==='ongoing'?'#f97316':'#a1a1aa'}"></div></div></div>`).join('')}${extensions.length>3?`<div style="font-size:10px;color:#888;margin-top:4px">+${extensions.length-3} more — click aggregation card for dialog</div>`:''}</div>`
                : '<div style="font-size:11px;color:#999;margin-top:6px">No extensions in this barangay</div>';
            const sdgHtml = sdgBreakdown.length
                ? `<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:3px">${sdgBreakdown.map((s: any) => `<span style="display:inline-flex;align-items:center;gap:2px;border:1px solid ${s.color};background:${s.color}14;color:${s.color};border-radius:5px;padding:1px 4px;font-size:10px;font-weight:700"><img src="${s.icon_url}" style="width:12px;height:12px;border-radius:2px;object-fit:cover" alt="SDG ${s.number}">SDG ${s.number}: ${s.count}</span>`).join('')}</div>`
                : '';
            const header = `<div style="font-weight:700;font-size:12px;color:#18181b">${props.name}: ${props.value} activit${props.value===1?'y':'ies'}${isAllBarangays.value ? '' : ' · hover for progress'}</div>`;
            layer.bindTooltip(`${header}${progHtml}${extListHtml}${sdgHtml}`, { sticky: true });
        }
    });
    const isHighlighted = (feature: any) =>
        highlightedBarangayIds.value.includes(Number(feature.id) || Number(feature.properties?.id)) ||
        highlightedBarangayIds.value.includes(Number(feature.properties?.id));
    geoLayer.setStyle((feature: any) => highlightStyle(feature, max, String(feature?.id) === String(selected.value?.id) || String(feature?.properties?.id) === String(selected.value?.id) || isHighlighted(feature)));

    if (markersLayer) {
        markersLayer.clearLayers();
        featuresToShow.forEach((feat: any) => {
            const center = feat.geometry?.type === 'Point' ? feat.geometry.coordinates : null;
            let latlng: L.LatLngExpression | null = null;
            if (center) {
                latlng = [center[1], center[0]] as any;
            } else if (feat.properties?.id) {
                const layer = (geoLayer!.getLayers() as any[]).find((l: any) => String(l.feature?.id) === String(feat.id));
                if (layer && (layer as any).getBounds) {
                    const bounds = (layer as any).getBounds();
                    if (bounds.isValid()) latlng = bounds.getCenter();
                }
            }
            if (latlng) {
                const val = feat.properties?.value ?? 0;
                if (!val) return;
                const icon = L.divIcon({
                    className: '',
                    html: `<div style="background:#EA580C;color:#fff;border-radius:9999px;padding:2px 6px;font-size:11px;font-weight:600;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;gap:4px;"><span style="width:8px;height:8px;background:#fff;border-radius:50%;display:inline-block;"></span>${val}</div>`,
                    iconSize: [40, 24],
                    iconAnchor: [20, 12],
                });
                const marker = L.marker(latlng as any, { icon, riseOnHover: true });
                marker.on('click', () => {
                    selected.value = feat;
                    const max2 = Math.max(...currentFeatures.value.map((f: any) => f.properties?.value ?? 0), 0);
                    geoLayer?.setStyle((f: any) => highlightStyle(f, max2, String(f?.id) === String(feat.id) || String(f?.properties?.id) === String(feat.id)));
                    if (isSurveyMode.value) void openAggBarangay(feat);
                });
                marker.addTo(markersLayer!);
            }
        });
    }

    renderActivityPins();

    if (fit && mapInstance && featuresToShow.length) {
        const bounds = geoLayer.getBounds();
        if (bounds.isValid()) mapInstance.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
    }
};

watch(mapMode, () => {
    fitPending = true;
    void fetchActive();
});
watch(selectedSurvey, () => {
    selectedFields.value = [];
    void fetchFields();
    fitPending = true;
    void fetchActive();
});
watch(selectedFields, () => {
    if (isSurveyMode.value) void fetchActive();
});
watch(selectedBarangays, () => {
    fitPending = true;
    void fetchActive();
    if (isSurveyMode.value) void fetchFieldTotals();
});
watch(selectedProgram, () => {
    fitPending = true;
    void fetchActive();
});
watch(selectedSdgs, () => {
    fitPending = true;
    void fetchActive();
});
watch(selectedStatuses, () => {
    fitPending = true;
    void fetchActive();
});
watch(selectedYear, () => {
    fitPending = true;
    void fetchActive();
});

const buildAggUrl = (barangayId?: number) => {
    const params = new URLSearchParams();
    params.set('survey_id', selectedSurvey.value);
    if (barangayId) {
        params.set('barangay_id', String(barangayId));
    } else if (selectedBarangays.value.length) {
        selectedBarangays.value.forEach((id) => params.append('barangay_ids[]', String(id)));
    }
    const qIds: string[] = [];
    const fCodes: string[] = [];
    selectedFields.value.forEach((v) => {
        if (v.startsWith('q_')) qIds.push(v.replace('q_', ''));
        else if (v.startsWith('field_')) fCodes.push(v.replace('field_', ''));
        else fCodes.push(v);
    });
    // Always include poor family (Category 1+2) for dialog/card consistency — even if not checked
    if (!fCodes.includes('poor_cat2') && availableFields.value.some((f) => f.value === 'field_poor_cat2')) {
        fCodes.push('poor_cat2');
    }
    qIds.forEach((id) => params.append('question_ids[]', id));
    fCodes.forEach((c) => params.append('field_codes[]', c));
    return `/api/map/aggregation?${params.toString()}`;
};

const openAggBarangay = async (feat: any) => {
    aggBarangay.value = feat;
    showAggDialog.value = true;
    aggLoading.value = true;
    aggDetail.value = null;
    try {
        const res = await fetch(buildAggUrl(feat.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        aggDetail.value = data.barangays?.[0] ?? null;
    } catch {
        // no-op
    } finally {
        aggLoading.value = false;
    }
};

const closeAggDialog = () => {
    showAggDialog.value = false;
    aggBarangay.value = null;
    aggDetail.value = null;
};

const openAllDialog = async () => {
    // Highlight barangays having household on the survey (value > 0)
    highlightedBarangayIds.value = currentFeatures.value.filter((f: any) => (f.properties?.value ?? 0) > 0).map((f: any) => Number(f.id) || Number(f.properties?.id));
    // Refresh map style to show highlight
    if (geoLayer && currentFeatures.value.length) {
        const max = Math.max(...currentFeatures.value.map((f: any) => f.properties?.value ?? 0), 0);
        geoLayer.setStyle((feature: any) => {
            const isHighlighted = highlightedBarangayIds.value.includes(Number(feature.id) || Number(feature.properties?.id));
            const isSelected = String(feature?.id) === String(selected.value?.id) || String(feature?.properties?.id) === String(selected.value?.id);
            return highlightStyle(feature, max, isSelected || isHighlighted);
        });
    }
    showAllDialog.value = true;
    allLoading.value = true;
    allDetail.value = null;
    openAllIds.value = [];
    try {
        const res = await fetch(buildAggUrl(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        allDetail.value = data;
    } catch {
        // no-op
    } finally {
        allLoading.value = false;
    }
};

const closeAllDialog = () => {
    showAllDialog.value = false;
    allDetail.value = null;
    highlightedBarangayIds.value = [];
    if (geoLayer && currentFeatures.value.length) {
        const max = Math.max(...currentFeatures.value.map((f: any) => f.properties?.value ?? 0), 0);
        geoLayer.setStyle((feature: any) => highlightStyle(feature, max, String(feature?.id) === String(selected.value?.id) || String(feature?.properties?.id) === String(selected.value?.id)));
    }
};

const toggleOpenAll = (id: number) => {
    const idx = openAllIds.value.indexOf(id);
    if (idx >= 0) openAllIds.value.splice(idx, 1);
    else openAllIds.value.push(id);
};

const openExtDialog = () => {
    // Highlight barangays having extension activities (value > 0)
    highlightedBarangayIds.value = currentFeatures.value.filter((f: any) => (f.properties?.value ?? 0) > 0).map((f: any) => Number(f.id) || Number(f.properties?.id));
    if (geoLayer && currentFeatures.value.length) {
        const max = Math.max(...currentFeatures.value.map((f: any) => f.properties?.value ?? 0), 0);
        geoLayer.setStyle((feature: any) => {
            const isHighlighted = highlightedBarangayIds.value.includes(Number(feature.id) || Number(feature.properties?.id));
            const isSelected = String(feature?.id) === String(selected.value?.id) || String(feature?.properties?.id) === String(selected.value?.id);
            return highlightStyle(feature, max, isSelected || isHighlighted);
        });
    }
    extOpenIds.value = [];
    showExtDialog.value = true;
};
const closeExtDialog = () => {
    showExtDialog.value = false;
    highlightedBarangayIds.value = [];
    if (geoLayer && currentFeatures.value.length) {
        const max = Math.max(...currentFeatures.value.map((f: any) => f.properties?.value ?? 0), 0);
        geoLayer.setStyle((feature: any) => highlightStyle(feature, max, String(feature?.id) === String(selected.value?.id) || String(feature?.properties?.id) === String(selected.value?.id)));
    }
};
const toggleExtOpen = (id: number) => {
    const idx = extOpenIds.value.indexOf(id);
    if (idx >= 0) extOpenIds.value.splice(idx, 1);
    else extOpenIds.value.push(id);
};

onMounted(() => {
    if (!mapEl.value) return;
    mapInstance = L.map(mapEl.value).setView([8.06, 123.75], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(mapInstance);
    geoLayer = L.geoJSON({ type: 'FeatureCollection', features: [] } as any, {
        style: (feature: any) => highlightStyle(feature, 0, false),
    }).addTo(mapInstance);
    markersLayer = L.layerGroup().addTo(mapInstance);
    activityLayer = L.layerGroup().addTo(mapInstance);
    updateMap();
});
</script>

<template>
    <Head title="Community Map" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-4rem)] flex-col lg:flex-row">
            <aside class="w-full space-y-4 overflow-y-auto border-b border-neutral-200 bg-white p-4 lg:h-full lg:w-96 lg:border-b-0 lg:border-r dark:border-neutral-800 dark:bg-neutral-900">
                <h1 class="font-heading text-lg font-semibold">Community Map</h1>
                <p class="text-xs text-neutral-400">Visualize community data per barangay. Switch between household survey data and extension activity mapping.</p>

                <!-- Mode toggle -->
                <div class="grid grid-cols-2 gap-2 rounded-xl border border-neutral-200 bg-neutral-100 p-1">
                    <button
                        type="button"
                        :class="mapMode === 'survey' ? 'bg-white text-brand-700 shadow-sm' : 'text-neutral-500'"
                        class="rounded-lg py-2 text-sm font-semibold transition"
                        @click="mapMode = 'survey'"
                    >
                        Household Survey
                    </button>
                    <button
                        type="button"
                        :class="mapMode === 'extension' ? 'bg-white text-brand-700 shadow-sm' : 'text-neutral-500'"
                        class="rounded-lg py-2 text-sm font-semibold transition"
                        @click="mapMode = 'extension'"
                    >
                        Extension Activities
                    </button>
                </div>

                <!-- SURVEY MODE -->
                <template v-if="isSurveyMode">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Community Household Survey *</label>
                        <select v-model="selectedSurvey" class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                            <option value="">— Select household survey —</option>
                            <option v-for="s in surveys" :key="s.id" :value="String(s.id)">{{ s.title }}</option>
                        </select>
                        <p v-if="!selectedSurvey" class="mt-1 text-[11px] text-amber-600">Please select a household survey to load its fields.</p>
                    </div>

                    <div v-if="selectedSurvey">
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Fields to render (multi-select)</label>
                        <div class="max-h-48 overflow-auto rounded-xl border border-neutral-200 bg-white p-2">
                            <template v-for="group in ['Household Conditions','Per-Member','Aggregate','Other']" :key="group">
                                <p v-if="availableFields.filter(f=>f.group===group).length" class="mt-2 px-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400">{{ group }}</p>
                                <label v-for="f in availableFields.filter(ff=>ff.group===group)" :key="f.value" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs hover:bg-neutral-50">
                                    <input type="checkbox" :value="f.value" v-model="selectedFields" class="size-3.5 accent-brand-500" />
                                    <span class="flex-1 truncate">{{ f.label }}</span>
                                    <span class="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold text-neutral-500">
                                        {{ fieldTotals[f.value] !== undefined ? Number(fieldTotals[f.value]).toLocaleString() : '–' }}
                                    </span>
                                </label>
                            </template>
                            <p v-if="!availableFields.length" class="px-2 py-2 text-xs text-neutral-400">No fields found for this survey.</p>
                        </div>
                        <p class="mt-1 text-[10px] text-neutral-400">{{ selectedFields.length }} fields selected — marker shows total household responses per barangay (breakdown per condition in dialog).</p>
                    </div>

                    <div class="rounded-xl border border-neutral-200 p-3">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-400">Legend — Response count intensity</p>
                        <div class="flex items-center gap-1.5 text-[10px] text-neutral-500">
                            <span>Low</span>
                            <span class="inline-block size-3 rounded-sm" style="background: #FFEDD5" />
                            <span class="inline-block size-3 rounded-sm" style="background: #FED7AA" />
                            <span class="inline-block size-3 rounded-sm" style="background: #FDBA74" />
                            <span class="inline-block size-3 rounded-sm" style="background: #FB923C" />
                            <span class="inline-block size-3 rounded-sm" style="background: #F97316" />
                            <span class="inline-block size-3 rounded-sm" style="background: #EA580C" />
                            <span>High</span>
                        </div>
                    </div>
                </template>

                <!-- EXTENSION MODE -->
                <template v-else>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Program</label>
                        <select v-model="selectedProgram" class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                            <option value="">— All programs —</option>
                            <option v-for="p in programs" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                        </select>
                        <p class="mt-1 text-[11px] text-neutral-400">Choropleth shows activity count per barangay; pins are individual activities (in-progress counted, city-wide counts but not pinned).</p>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-xl border border-neutral-200 bg-white p-2">
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Status</label>
                            <label class="flex cursor-pointer items-center gap-1.5 rounded px-1 py-1 text-xs hover:bg-neutral-50"><input type="checkbox" value="ongoing" v-model="selectedStatuses" class="size-3.5 accent-brand-500" /> In Progress (ongoing)</label>
                            <label class="flex cursor-pointer items-center gap-1.5 rounded px-1 py-1 text-xs hover:bg-neutral-50"><input type="checkbox" value="completed" v-model="selectedStatuses" class="size-3.5 accent-brand-500" /> Completed</label>
                            <label class="flex cursor-pointer items-center gap-1.5 rounded px-1 py-1 text-xs hover:bg-neutral-50"><input type="checkbox" value="planned" v-model="selectedStatuses" class="size-3.5 accent-brand-500" /> Planned</label>
                            <p class="mt-1 text-[10px] text-neutral-400">{{ selectedStatuses.length ? selectedStatuses.join(', ') : 'All statuses (including in-progress)' }}</p>
                        </div>
                        <div class="rounded-xl border border-neutral-200 bg-white p-2">
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Year</label>
                            <select v-model="selectedYear" class="w-full rounded-lg border border-neutral-200 bg-white px-2 py-1.5 text-xs outline-none focus:border-brand-500">
                                <option value="">All years</option>
                                <option v-for="y in props.extensionYears" :key="y" :value="String(y)">{{ y }}</option>
                            </select>
                            <p class="mt-1 text-[10px] text-neutral-400">Filters by start/end year</p>
                            <button v-if="selectedYear || selectedStatuses.length" type="button" class="mt-2 text-[11px] font-medium text-brand-600 hover:underline" @click="selectedStatuses=[]; selectedYear=''">Clear filters</button>
                        </div>
                    </div>

                    <div class="rounded-xl border border-neutral-200 p-2">
                        <div class="flex items-center justify-between">
                            <label class="text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Filter by SDG (ANY)</label>
                            <div class="flex items-center gap-2">
                                <button type="button" class="text-[11px] font-medium text-brand-600 hover:underline" @click="toggleSelectAllSdg">{{ isAllSdgs ? 'Deselect all' : 'Select all' }}</button>
                                <button v-if="selectedSdgs.length" type="button" class="text-[11px] font-medium text-brand-600 hover:underline" @click="clearSdgFilter">Clear ({{ selectedSdgs.length }})</button>
                                <button v-else type="button" class="text-[11px] text-neutral-400" @click="sdgFilterOpen = !sdgFilterOpen">{{ sdgFilterOpen ? 'Hide' : 'Show' }}</button>
                            </div>
                        </div>
                        <p class="mt-1 text-[11px] text-neutral-400">Activity must match at least one selected SDG. Icons shown on pins; hover choropleth for per-SDG breakdown.</p>
                        <div v-if="sdgFilterOpen || selectedSdgs.length" class="mt-2 grid grid-cols-1 gap-1.5 max-h-64 overflow-auto pr-1">
                            <label
                                v-for="s in sdgs"
                                :key="s.id"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border px-2 py-1.5 transition"
                                :class="selectedSdgs.includes(s.id) ? 'bg-neutral-50' : 'border-neutral-200 hover:bg-neutral-50'"
                                :style="selectedSdgs.includes(s.id) ? { borderColor: s.color, backgroundColor: s.color + '12' } : {}"
                            >
                                <input type="checkbox" :value="s.id" :checked="selectedSdgs.includes(s.id)" @change="toggleSdg(s.id)" class="size-3.5 accent-brand-500" />
                                <img :src="s.icon_url" :alt="s.title" class="size-7 rounded-md object-cover border bg-white" :style="{ borderColor: s.color }" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs font-semibold leading-tight" :style="{ color: selectedSdgs.includes(s.id) ? s.color : '#3f3f46' }">SDG {{ s.number }} — {{ s.short_title || s.title }}</span>
                                    <span class="block text-[10px] leading-tight text-neutral-500 truncate">{{ s.title }}</span>
                                </span>
                            </label>
                        </div>
                        <div v-if="!sdgFilterOpen && !selectedSdgs.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="s in sdgs.slice(0, 8)" :key="s.id" class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold bg-white" :style="{ borderColor: s.color, color: s.color }" :title="s.title"><img :src="s.icon_url" class="size-4 rounded-sm object-cover" alt="" />{{ s.number }}</span>
                            <span class="text-[11px] text-neutral-400">+{{ sdgs.length - 8 }} more — click Show</span>
                        </div>
                    </div>

                    <div class="rounded-xl border border-neutral-200 p-3">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-400">Legend — Activity count intensity</p>
                        <div class="flex items-center gap-1.5 text-[10px] text-neutral-500">
                            <span>Low</span>
                            <span class="inline-block size-3 rounded-sm" style="background: #FFEDD5" />
                            <span class="inline-block size-3 rounded-sm" style="background: #FED7AA" />
                            <span class="inline-block size-3 rounded-sm" style="background: #FDBA74" />
                            <span class="inline-block size-3 rounded-sm" style="background: #FB923C" />
                            <span class="inline-block size-3 rounded-sm" style="background: #F97316" />
                            <span class="inline-block size-3 rounded-sm" style="background: #EA580C" />
                            <span>High</span>
                        </div>
                        <p class="mt-2 text-[10px] text-neutral-500">Pins use SDG icons — single SDG shows its icon, multiple shows +N badge. Hover barangay for per-SDG itemization.</p>
                    </div>
                </template>

                <p v-if="isSurveyMode && hasSurvey" class="rounded-lg border border-neutral-200 px-3 py-2 text-center text-xs font-semibold text-brand-600">
                    {{ currentFeatures.length }} barangays shown
                </p>
                <p v-else-if="isSurveyMode" class="rounded-lg border border-neutral-200 px-3 py-2 text-center text-xs font-semibold text-neutral-400">
                    Select a survey to load the map.
                </p>
                <p v-else class="rounded-lg border border-neutral-200 px-3 py-2 text-center text-xs font-semibold text-brand-600">
                    {{ currentFeatures.length }} barangays shown · {{ activityShown }} activity pins
                </p>
            </aside>

            <div class="relative min-h-[60vh] flex-1 lg:min-h-0">
                <div ref="mapEl" class="absolute inset-0" />

                <!-- Loading overlay -->
                <div v-if="mapLoading" class="absolute inset-0 z-[1050] flex flex-col items-center justify-center gap-3 bg-white/60 backdrop-blur-sm">
                    <span class="size-9 animate-spin rounded-full border-[3px] border-neutral-200 border-t-brand-500" />
                    <p class="text-xs font-medium text-neutral-500">Loading map data…</p>
                </div>

                <!-- Barangay filter overlay -->
                <div class="absolute left-1/2 top-3 z-[1100] w-72 -translate-x-1/2">
                    <div class="rounded-2xl border border-white/40 bg-white/80 p-2 shadow-lg backdrop-blur-md">
                        <button type="button" class="flex w-full items-center justify-between px-2 py-1.5 text-sm" @click="barangayFilterOpen = !barangayFilterOpen">
                            <span class="font-semibold text-neutral-700">Barangays</span>
                            <span class="rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-semibold text-brand-700">
                                {{ isAllBarangays ? `All (${props.barangays.length})` : `${selectedBarangays.length} selected` }}
                                <span class="ml-1 text-brand-400">{{ barangayFilterOpen ? '▴' : '▾' }}</span>
                            </span>
                        </button>
                        <div v-if="barangayFilterOpen" class="mt-2">
                            <div class="flex gap-2">
                                <input v-model="barangaySearch" placeholder="Search barangay..." class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-1.5 text-sm outline-none focus:border-brand-500" />
                                <button type="button" class="shrink-0 rounded-lg border px-2.5 py-1.5 text-xs hover:bg-neutral-50" @click="clearBarangayFilter">All</button>
                            </div>
                            <div class="mt-2 max-h-44 overflow-auto rounded-lg border border-neutral-100 bg-neutral-50 p-1">
                                <label v-for="b in filteredBarangays.slice(0, 50)" :key="b.id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-xs hover:bg-white">
                                    <input type="checkbox" :checked="pendingBarangays.includes(b.id)" @change="toggleBarangay(b.id)" class="size-3.5 accent-brand-500" />
                                    <span class="truncate">{{ b.name }}</span>
                                </label>
                                <p v-if="!filteredBarangays.length" class="px-2 py-2 text-xs text-neutral-400">No matching barangay.</p>
                            </div>
                            <div v-if="pendingBarangays.length" class="mt-2 flex flex-wrap gap-1">
                                <span v-for="id in pendingBarangays" :key="id" class="inline-flex items-center gap-1 rounded bg-brand-100 px-2 py-0.5 text-[11px] font-medium text-brand-700">
                                    {{ props.barangays.find((b) => b.id === id)?.name ?? id }}
                                    <button type="button" class="text-brand-400 hover:text-brand-700" @click="toggleBarangay(id)">✕</button>
                                </span>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button
                                    v-if="pendingBarangays.length"
                                    type="button"
                                    class="flex-1 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600"
                                    @click="applyBarangayFilter"
                                >
                                    Filter ({{ pendingBarangays.length }})
                                </button>
                                <button type="button" class="flex-1 rounded-lg border px-3 py-1.5 text-xs hover:bg-neutral-50" @click="barangayFilterOpen = false">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aggregation overlay card -->
                <div
                    v-if="currentFeatures.length"
                    class="absolute right-3 top-3 z-[1100] w-64 cursor-pointer rounded-2xl border border-white/40 bg-white/70 p-3 shadow-lg backdrop-blur-md transition hover:bg-white/85"
                    role="button"
                    tabindex="0"
                    @click="isSurveyMode ? openAllDialog() : openExtDialog()"
                    @keydown.enter="isSurveyMode ? openAllDialog() : openExtDialog()"
                >
                    <p class="mb-2 flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
                        <span>Aggregation</span>
                        <span class="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-semibold text-brand-700">{{ isSurveyMode ? 'View all →' : 'View programs →' }}</span>
                    </p>
                    <template v-if="isSurveyMode">
                        <div class="space-y-1.5 text-sm">
                            <div class="flex justify-between"><span class="text-neutral-500">Barangays</span><span class="font-semibold">{{ aggTotals.count }}</span></div>
                            <div class="flex justify-between"><span class="text-neutral-500">Total responses</span><span class="font-semibold">{{ aggTotals.households.toLocaleString() }}</span></div>
                            <div class="flex justify-between"><span class="text-neutral-500">Poor Family</span><span class="font-semibold text-orange-600">{{ aggTotals.poor.toLocaleString() }}</span></div>
                        </div>
                    </template>
                    <template v-else>
                        <div class="space-y-1.5 text-sm">
                            <div class="flex justify-between"><span class="text-neutral-500">Barangays</span><span class="font-semibold">{{ extTotals.count }}</span></div>
                            <div class="flex justify-between"><span class="text-neutral-500">Total activities</span><span class="font-semibold">{{ extTotals.total.toLocaleString() }}</span></div>
                            <div v-if="extCityWide" class="flex justify-between text-xs"><span class="text-neutral-400">City-wide (no pin)</span><span class="font-semibold text-neutral-500">{{ extCityWide }}</span></div>
                            <div class="flex justify-between"><span class="text-neutral-500">Program</span><span class="font-semibold truncate ml-2 text-right">{{ selectedProgram ? (programs.find((p) => String(p.id) === selectedProgram)?.name ?? '') : 'All programs' }}</span></div>
                            <div v-if="selectedStatuses.length" class="flex justify-between text-xs"><span class="text-neutral-400">Status</span><span class="font-semibold capitalize">{{ selectedStatuses.join(', ') }}</span></div>
                            <div v-if="selectedYear" class="flex justify-between text-xs"><span class="text-neutral-400">Year</span><span class="font-semibold">{{ selectedYear }}</span></div>
                        </div>
                    </template>
                    <div v-if="highLow" class="mt-2 space-y-1 border-t border-neutral-200/70 pt-2">
                        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400">{{ isSurveyMode ? 'Highest / Lowest responses' : 'Highest / Lowest activities' }}</p>
                        <div class="flex items-center justify-between text-xs">
                            <span class="truncate pr-2 text-neutral-600">{{ highLow.highest.name }}</span>
                            <span class="shrink-0 font-semibold text-neutral-800">{{ highLow.highest.value.toLocaleString() }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="truncate pr-2 text-neutral-500">{{ highLow.lowest.name }}</span>
                            <span class="shrink-0 font-semibold text-neutral-500">{{ highLow.lowest.value.toLocaleString() }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Barangay aggregation dialog -->
            <div v-if="showAggDialog && aggBarangay" class="fixed inset-0 z-[1200] flex items-start justify-center overflow-auto bg-black/40 p-4 sm:p-6" @click.self="closeAggDialog">
                <div class="my-8 w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-lg font-semibold text-brand-700">{{ aggBarangay.properties.name }}</h2>
                            <p class="text-xs text-neutral-500">
                                {{ Number(aggBarangay.properties.total_households ?? 0).toLocaleString() }} households · selected fields per answer
                            </p>
                        </div>
                        <button type="button" class="rounded-lg border px-3 py-1.5 text-xs hover:bg-neutral-50" @click="closeAggDialog">Close</button>
                    </div>

                    <div v-if="aggLoading" class="p-6 text-center text-xs text-neutral-400">Loading aggregation…</div>
                    <template v-else-if="aggDetail && aggDetail.fields.length">
                        <div class="mt-4 space-y-2">
                            <div v-for="f in aggDetail.fields" :key="f.key" class="rounded-xl border border-neutral-100 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-neutral-500">{{ f.label }}</p>
                                    <span v-if="f.type === 'count'" class="text-xs font-semibold text-neutral-700">{{ Number(f.total).toLocaleString() }}</span>
                                </div>
                                <ul v-if="f.type === 'choice'" class="mt-2 space-y-1">
                                    <li v-for="it in f.items" :key="it.name" class="flex items-center gap-2 text-xs">
                                        <span class="shrink-0 text-neutral-600">{{ it.name }}</span>
                                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100">
                                            <span
                                                class="block h-full rounded-full bg-brand-400"
                                                :style="{ width: (f.total ? (it.count / f.total) * 100 : 0) + '%' }"
                                            />
                                        </span>
                                        <span class="shrink-0 font-semibold text-neutral-800">{{ it.count }}</span>
                                    </li>
                                    <li v-if="!f.items.length" class="text-xs text-neutral-400">No responses.</li>
                                </ul>
                                <div v-else-if="f.type === 'text'" class="mt-2">
                                    <ul v-if="f.items.length" class="space-y-1.5">
                                        <li v-for="it in f.items" :key="it.name" class="flex items-start gap-2 text-xs text-neutral-600">
                                            <span class="mt-1 size-1.5 shrink-0 rounded-full bg-brand-400" />
                                            <span class="flex-1">{{ it.name }}<span v-if="it.count > 1" class="text-neutral-400"> (× {{ it.count }})</span></span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-neutral-400">No written responses.</p>
                                </div>
                                <p v-else-if="f.type === 'count'" class="mt-1.5 text-xs text-neutral-600">Total: {{ Number(f.total).toLocaleString() }}</p>
                            </div>
                        </div>
                    </template>
                    <p v-else class="p-4 text-center text-xs text-neutral-400">Select fields to see itemized aggregation.</p>
                </div>
            </div>

            <!-- View all aggregations dialog -->
            <div v-if="showAllDialog" class="fixed inset-0 z-[1200] flex items-start justify-center overflow-auto bg-black/40 p-4 sm:p-6" @click.self="closeAllDialog">
                <div class="my-8 w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-lg font-semibold text-brand-700">All Barangays — Aggregations</h2>
                            <p class="text-xs text-neutral-500">Itemized aggregation per barangay · {{ selectedFields.length }} fields selected</p>
                        </div>
                        <button type="button" class="rounded-lg border px-3 py-1.5 text-xs hover:bg-neutral-50" @click="closeAllDialog">Close</button>
                    </div>

                    <div v-if="selectedFields.length" class="mt-4 grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-lg bg-neutral-50 p-3 text-center">
                            <p class="text-lg font-bold text-neutral-800">{{ allTotals.households.toLocaleString() }}</p>
                            <p class="text-[11px] text-neutral-500">Total responses</p>
                        </div>
                        <div class="rounded-lg bg-orange-50 p-3 text-center">
                            <p class="text-lg font-bold text-orange-600">{{ allTotals.poor.toLocaleString() }}</p>
                            <p class="text-[11px] text-neutral-500">Poor Family</p>
                        </div>
                        <div class="rounded-lg bg-neutral-50 p-3 text-center">
                            <p class="text-lg font-bold text-neutral-800">{{ allTotals.count }}</p>
                            <p class="text-[11px] text-neutral-500">Barangays</p>
                        </div>
                    </div>

                    <div class="mt-4 max-h-[60vh] overflow-auto rounded-xl border border-neutral-200">
                        <div v-if="allLoading" class="p-6 text-center text-xs text-neutral-400">Loading aggregations…</div>
                        <template v-else-if="allDetail?.barangays?.length">
                            <div
                                v-for="b in allDetail.barangays"
                                :key="b.id"
                                :class="[
                                    'border-b last:border-b-0',
                                    Number(b.households ?? 0) > 0 ? 'bg-brand-50 border-l-4 border-brand-500' : 'border-neutral-100 bg-white',
                                ]"
                            >
                                <button
                                    type="button"
                                    :class="[
                                        'flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition',
                                        Number(b.households ?? 0) > 0 ? 'bg-brand-500 text-white hover:bg-brand-600' : 'hover:bg-neutral-50',
                                    ]"
                                    @click="toggleOpenAll(b.id)"
                                >
                                    <span :class="['font-medium', Number(b.households ?? 0) > 0 ? 'text-white' : 'text-neutral-700']">
                                        {{ b.name }}
                                        <span
                                            v-if="Number(b.households ?? 0) > 0"
                                            class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-white px-1.5 py-0.5 text-[10px] font-bold text-brand-600"
                                            >{{ Number(b.households ?? 0) }} <span class="hidden sm:inline">responses</span></span
                                        >
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span
                                            :class="['text-xs', Number(b.households ?? 0) > 0 ? 'font-semibold text-white' : 'text-neutral-500']"
                                            >{{ Number(b.households ?? 0).toLocaleString() }} households</span
                                        >
                                        <span class="text-[10px] text-neutral-400">{{ openAllIds.includes(b.id) ? '−' : '+' }}</span>
                                    </span>
                                </button>
                                <div v-if="openAllIds.includes(b.id)" class="space-y-2 bg-neutral-50/70 px-3 py-3">
                                    <div v-for="f in b.fields" :key="f.key" class="rounded-xl border border-neutral-100 bg-white p-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-neutral-500">{{ f.label }}</p>
                                            <span v-if="f.type === 'count'" class="text-xs font-semibold text-neutral-700">{{ Number(f.total).toLocaleString() }}</span>
                                        </div>
                                        <ul v-if="f.type === 'choice'" class="mt-2 space-y-1">
                                            <li v-for="it in f.items" :key="it.name" class="flex items-center gap-2 text-xs">
                                                <span class="shrink-0 text-neutral-600">{{ it.name }}</span>
                                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100">
                                                    <span
                                                        class="block h-full rounded-full bg-brand-400"
                                                        :style="{ width: (f.total ? (it.count / f.total) * 100 : 0) + '%' }"
                                                    />
                                                </span>
                                                <span class="shrink-0 font-semibold text-neutral-800">{{ it.count }}</span>
                                            </li>
                                            <li v-if="!f.items.length" class="text-xs text-neutral-400">No responses.</li>
                                        </ul>
                                        <div v-else-if="f.type === 'text'" class="mt-2">
                                            <ul v-if="f.items.length" class="space-y-1.5">
                                                <li v-for="it in f.items" :key="it.name" class="flex items-start gap-2 text-xs text-neutral-600">
                                                    <span class="mt-1 size-1.5 shrink-0 rounded-full bg-brand-400" />
                                                    <span class="flex-1">{{ it.name }}<span v-if="it.count > 1" class="text-neutral-400"> (× {{ it.count }})</span></span>
                                                </li>
                                            </ul>
                                            <p v-else class="text-xs text-neutral-400">No written responses.</p>
                                        </div>
                                        <p v-else-if="f.type === 'count'" class="mt-1.5 text-xs text-neutral-600">Total: {{ Number(f.total).toLocaleString() }}</p>
                                    </div>
                                    <p v-if="!b.fields.length" class="text-xs text-neutral-400">No fields selected.</p>
                                </div>
                            </div>
                        </template>
                        <p v-else class="p-6 text-center text-xs text-neutral-400">No data.</p>
                    </div>
                </div>
            </div>

            <!-- Extension Programs detail dialog (shown when clicking aggregation card in extension mode) -->
            <div v-if="showExtDialog" class="fixed inset-0 z-[1200] flex items-start justify-center overflow-auto bg-black/40 p-4 sm:p-6" @click.self="closeExtDialog">
                <div class="my-8 w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-lg font-semibold text-brand-700">Extension Activities — Programs</h2>
                            <p class="text-xs text-neutral-500">
                                {{ extTotals.total }} activit{{ extTotals.total===1?'y':'ies' }} · {{ extTotals.count }} barangays{{ extCityWide ? ` · ${extCityWide} city-wide (no pin, counted)` : '' }}{{ selectedProgram ? ` · ${programs.find(p=>String(p.id)===selectedProgram)?.name}` : ' · All programs' }}{{ selectedStatuses.length ? ` · ${selectedStatuses.join(', ')}` : '' }}{{ selectedYear ? ` · ${selectedYear}` : '' }}{{ selectedBarangays.length ? ` · ${selectedBarangays.length} barangay(s) filtered` : ' · All barangays (pins hidden, count only)' }}{{ selectedSdgs.length ? ` · ${selectedSdgs.length} SDG(s)` : '' }}
                            </p>
                        </div>
                        <button type="button" class="rounded-lg border px-3 py-1.5 text-xs hover:bg-neutral-50" @click="closeExtDialog">Close</button>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-lg bg-neutral-50 p-3 text-center"><p class="text-lg font-bold text-neutral-800">{{ extTotals.total }}</p><p class="text-[11px] text-neutral-500">Total activities</p></div>
                        <div class="rounded-lg bg-orange-50 p-3 text-center"><p class="text-lg font-bold text-orange-600">{{ extTotals.count }}</p><p class="text-[11px] text-neutral-500">Barangays with activities</p></div>
                        <div class="rounded-lg bg-sky-50 p-3 text-center"><p class="text-lg font-bold text-sky-600">{{ extCityWide }}</p><p class="text-[11px] text-neutral-500">City-wide (no pin)</p></div>
                    </div>

                    <div v-if="extProgramTotals.length" class="mt-4">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Activities per Program</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div v-for="pt in extProgramTotals" :key="pt.program_id" class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-3 py-2">
                                <span class="text-sm font-medium text-neutral-700">{{ pt.program_name }}</span>
                                <span class="rounded-full bg-brand-500 px-2.5 py-0.5 text-xs font-bold text-white">{{ pt.count }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-4 text-xs text-neutral-400">No program breakdown available.</p>

                    <div class="mt-4 max-h-[55vh] overflow-auto rounded-xl border border-neutral-200">
                        <template v-if="currentFeatures.length">
                            <div v-for="feat in [...currentFeatures].sort((a:any,b:any)=> (b.properties?.value??0) - (a.properties?.value??0))" :key="feat.id" class="border-b border-neutral-100 last:border-b-0">
                                <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition hover:bg-neutral-50" @click="toggleExtOpen(Number(feat.id))">
                                    <span class="font-medium text-neutral-700">{{ feat.properties.name }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="rounded-full bg-brand-500/10 px-2 py-0.5 text-xs font-semibold text-brand-700">{{ feat.properties.value }} activit{{ feat.properties.value===1?'y':'ies' }}</span>
                                        <span class="text-[10px] text-neutral-400">{{ extOpenIds.includes(Number(feat.id)) ? '−' : '+' }}</span>
                                    </span>
                                </button>
                                <div v-if="extOpenIds.includes(Number(feat.id))" class="space-y-3 bg-neutral-50/70 px-3 py-3">
                                    <div v-if="(feat.properties.program_breakdown ?? []).length" class="flex flex-wrap gap-1.5">
                                        <span v-for="pb in feat.properties.program_breakdown" :key="pb.program_id" class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-white px-2.5 py-1 text-xs">
                                            <span class="font-medium text-neutral-700">{{ pb.program_name }}</span>
                                            <span class="text-neutral-500">{{ pb.count }} · {{ pb.avg_progress }}% avg</span>
                                        </span>
                                    </div>
                                    <div v-if="(feat.properties.extensions ?? []).length" class="space-y-2">
                                        <div v-for="ext in feat.properties.extensions" :key="ext.id" class="rounded-xl border border-neutral-200 bg-white p-3">
                                            <div class="flex items-start justify-between gap-2">
                                                <p class="text-sm font-semibold leading-tight text-neutral-800">{{ ext.title }}</p>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="ext.status==='completed' ? 'bg-emerald-500/10 text-emerald-600' : ext.status==='ongoing' ? 'bg-amber-500/10 text-amber-600' : 'bg-neutral-100 text-neutral-600'">{{ ext.status }}</span>
                                            </div>
                                            <p class="mt-1 text-xs text-neutral-500">{{ ext.program ?? '—' }} · {{ ext.barangay ?? 'City-wide' }}<span v-if="ext.location"> · {{ ext.location }}</span></p>
                                            <div class="mt-2 flex items-center gap-2">
                                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full transition-all" :class="ext.status==='completed' ? 'bg-emerald-500' : ext.status==='ongoing' ? 'bg-brand-500' : 'bg-neutral-400'" :style="{ width: ext.progress + '%' }"></div></div>
                                                <span class="text-xs font-semibold" :class="ext.status==='completed' ? 'text-emerald-600' : 'text-brand-600'">{{ ext.progress }}%</span>
                                            </div>
                                            <p class="mt-1 text-[10px] text-neutral-400">{{ ext.start_date ?? '' }} → {{ ext.end_date ?? '' }}</p>
                                        </div>
                                    </div>
                                    <div v-if="(feat.properties.sdg_breakdown ?? []).length" class="flex flex-wrap gap-1.5">
                                        <span v-for="s in feat.properties.sdg_breakdown" :key="s.id" class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold bg-white" :style="{ borderColor: s.color, color: s.color }"><img :src="s.icon_url" class="size-3 rounded-sm object-cover" alt="" />SDG {{ s.number }}: {{ s.count }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <p v-else class="p-6 text-center text-xs text-neutral-400">No barangay data for current filters.</p>
                    </div>
                    <p class="mt-3 text-[10px] text-neutral-400">In-progress activities are included even before completion; city-wide activities are counted in totals but not pinned. Hover any barangay polygon to see extension info + progress; when viewing All barangays, pins are hidden to reduce clutter (count remains).</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
