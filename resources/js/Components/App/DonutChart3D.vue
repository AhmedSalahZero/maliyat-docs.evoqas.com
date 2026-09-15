<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — DonutChart3D.vue
//
//  An extruded donut, drawn as plain SVG. No charting library: the
//  one chart this app needs would have cost more in bundle size than
//  the whole feature, and a library's canvas wouldn't inherit the
//  theme tokens or flip for Arabic without a fight.
//
//  How the 3D read is produced:
//    • Every slice is drawn on an ELLIPSE (rx > ry), which is what a
//      circle looks like when you tilt it away from you.
//    • The same ring is drawn twice — once offset downward in a
//      darkened shade (the extruded side wall), once on top in the
//      real colour. The top layer hides the back half of the wall,
//      so only the front edge shows, which is exactly what a real
//      solid would do.
//
//  Slices arrive pre-sorted and pre-limited from the server; this
//  component only draws what it is given.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';

const props = defineProps({
    // [{ name, value }] — anything with a `value` and a `name`.
    slices: { type: Array, default: () => [] },
    // Rendered in the middle of the ring.
    centerLabel: { type: String, default: '' },
    centerValue: { type: String, default: '' },
    height: { type: Number, default: 230 },
});

// Six hues that stay distinguishable in both themes and don't collide
// with the semantic money colours used elsewhere on the page.
const PALETTE = [
    '#2D6CDF', // blue
    '#16A34A', // green
    '#06AED4', // cyan
    '#F59E0B', // amber
    '#8B5CF6', // violet
    '#EC4899', // pink
];

const VIEW_W = 320;
const CX = VIEW_W / 2;
const RX = 118;
const RY = 62;          // squashed vertically → the tilt
const HOLE = 0.64;      // inner radius as a fraction of the outer
const DEPTH = 22;       // how far the side wall drops

const CY = computed(() => props.height / 2 - DEPTH / 2);

const total = computed(() =>
    props.slices.reduce((sum, slice) => sum + Math.max(0, Number(slice.value) || 0), 0)
);

/** Darken a hex colour for the extruded wall. */
function shade(hex, factor = 0.62) {
    const n = parseInt(hex.slice(1), 16);
    const r = Math.round(((n >> 16) & 255) * factor);
    const g = Math.round(((n >> 8) & 255) * factor);
    const b = Math.round((n & 255) * factor);
    return `rgb(${r}, ${g}, ${b})`;
}

function pointOn(angle, rx, ry, cy) {
    return [CX + rx * Math.cos(angle), cy + ry * Math.sin(angle)];
}

/** One ring segment as an SVG path on the ellipse. */
function segmentPath(startAngle, endAngle, cy) {
    const large = endAngle - startAngle > Math.PI ? 1 : 0;

    const [ox1, oy1] = pointOn(startAngle, RX, RY, cy);
    const [ox2, oy2] = pointOn(endAngle, RX, RY, cy);
    const [ix2, iy2] = pointOn(endAngle, RX * HOLE, RY * HOLE, cy);
    const [ix1, iy1] = pointOn(startAngle, RX * HOLE, RY * HOLE, cy);

    return [
        `M ${ox1} ${oy1}`,
        `A ${RX} ${RY} 0 ${large} 1 ${ox2} ${oy2}`,
        `L ${ix2} ${iy2}`,
        `A ${RX * HOLE} ${RY * HOLE} 0 ${large} 0 ${ix1} ${iy1}`,
        'Z',
    ].join(' ');
}

const computedSlices = computed(() => {
    if (total.value <= 0) return [];

    // Start at the top of the ellipse so the largest slice reads first.
    let angle = -Math.PI / 2;

    return props.slices.map((slice, index) => {
        const value = Math.max(0, Number(slice.value) || 0);
        const share = value / total.value;
        const start = angle;
        const end   = angle + share * Math.PI * 2;
        angle = end;

        const color = PALETTE[index % PALETTE.length];

        return {
            name: slice.name,
            value,
            share,
            percent: Math.round(share * 1000) / 10,
            color,
            wallColor: shade(color),
            topPath:  segmentPath(start, end, CY.value),
            wallPath: segmentPath(start, end, CY.value + DEPTH),
        };
    });
});
</script>

<template>
    <div class="donut3d">
        <svg
            v-if="computedSlices.length"
            class="donut3d__svg"
            :viewBox="`0 0 ${VIEW_W} ${props.height}`"
            :style="{ height: props.height + 'px' }"
            role="img"
            :aria-label="props.centerLabel"
        >
            <!-- Side wall: same ring, dropped and darkened. Drawn
                 first so the top face covers its back half. -->
            <g>
                <path
                    v-for="slice in computedSlices"
                    :key="`wall-${slice.name}`"
                    :d="slice.wallPath"
                    :fill="slice.wallColor"
                />
            </g>

            <!-- Top face -->
            <g>
                <path
                    v-for="slice in computedSlices"
                    :key="`top-${slice.name}`"
                    :d="slice.topPath"
                    :fill="slice.color"
                    stroke="var(--color-surface)"
                    stroke-width="1"
                >
                    <title>{{ slice.name }} — {{ slice.percent }}%</title>
                </path>
            </g>

            <!-- Centre readout -->
            <text
                v-if="props.centerValue"
                :x="CX"
                :y="CY + 2"
                class="donut3d__center-value"
                text-anchor="middle"
            >{{ props.centerValue }}</text>
            <text
                v-if="props.centerLabel"
                :x="CX"
                :y="CY + 20"
                class="donut3d__center-label"
                text-anchor="middle"
            >{{ props.centerLabel }}</text>
        </svg>

        <ul v-if="computedSlices.length" class="donut3d__legend">
            <li v-for="slice in computedSlices" :key="slice.name" class="donut3d__legend-item">
                <span class="donut3d__swatch" :style="{ background: slice.color }"></span>
                <span class="donut3d__legend-name">{{ slice.name }}</span>
                <span class="donut3d__legend-share">{{ slice.percent }}%</span>
            </li>
        </ul>

        <p v-if="!computedSlices.length" class="donut3d__empty">
            <slot name="empty">—</slot>
        </p>
    </div>
</template>

<style scoped>
.donut3d {
    display: flex;
    align-items: center;
    gap: 22px;
    flex-wrap: wrap;
}

.donut3d__svg {
    flex: 0 0 auto;
    max-width: 100%;
    overflow: visible;
}

.donut3d__center-value {
    font-family: var(--font-mono);
    font-size: 15px;
    font-weight: 700;
    fill: var(--color-text-primary);
}

.donut3d__center-label {
    font-size: 10.5px;
    fill: var(--color-text-muted);
}

.donut3d__legend {
    /* Capped so a name and its share stay visually paired. Left to
       flex:1 it spanned the whole card and the percentages ended up
       against the far edge, reading as a separate column. */
    flex: 0 1 300px;
    min-width: 0;
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.donut3d__legend-item {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 13px;
    min-width: 0;
}

.donut3d__swatch {
    width: 11px;
    height: 11px;
    border-radius: 3px;
    flex-shrink: 0;
}

.donut3d__legend-name {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--color-text-secondary);
}

.donut3d__legend-share {
    font-family: var(--font-mono);
    font-weight: 600;
    color: var(--color-text-primary);
    flex-shrink: 0;
}

.donut3d__empty {
    margin: 0;
    padding: 28px 0;
    width: 100%;
    text-align: center;
    color: var(--color-text-muted);
    font-size: 13.5px;
}

@media (max-width: 560px) {
    .donut3d { justify-content: center; }
    .donut3d__legend { flex-basis: 100%; }
}
</style>
