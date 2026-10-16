<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { formatNaira } from '@/lib/money';

/**
 * Profit per day as columns. One series, so no legend: the card title names it.
 * Loss days drop below the baseline. Hover (or tap) a column for the day's figures;
 * the same numbers are in a table for screen readers.
 */
const props = defineProps<{
    days: { date: string; profit: number; volume: number; count: number }[];
    today: string;
}>();

// Drawn at the width it is shown at, so text stays 11px on a phone as on a laptop.
const box = useTemplateRef<HTMLDivElement>('box');
const width = ref(560);
let observer: ResizeObserver | null = null;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(280, Math.round(entry.contentRect.width));
    });

    if (box.value) {
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const H = 180;
const PAD = { top: 22, right: 8, bottom: 26, left: 44 };
const W = computed(() => width.value);
const plotW = computed(() => W.value - PAD.left - PAD.right);
const plotH = H - PAD.top - PAD.bottom;
// Never wider than 24px, and never more than half its slot on a narrow screen.
const BAR = computed(() => Math.min(24, Math.max(10, band.value * 0.5)));
const RADIUS = 4;

/** Round the axis to clean steps: 0 / 5K / 10K … */
const scale = computed(() => {
    const values = props.days.map((d) => d.profit / 100);
    const max = Math.max(0, ...values);
    const min = Math.min(0, ...values);
    const span = max - min || 1;
    const rough = span / 3;
    const pow = 10 ** Math.floor(Math.log10(rough));
    const step =
        [1, 2, 2.5, 5, 10].map((m) => m * pow).find((s) => s >= rough) ??
        pow * 10;
    const top = Math.ceil(max / step) * step || step;
    const bottom = Math.floor(min / step) * step;
    const ticks: number[] = [];

    for (let v = bottom; v <= top + step / 2; v += step) {
        ticks.push(Math.round(v * 100) / 100);
    }

    const y = (naira: number) =>
        PAD.top + ((top - naira) / (top - bottom)) * plotH;

    return { ticks, y, zero: y(0) };
});

const band = computed(() => plotW.value / Math.max(1, props.days.length));

function bar(i: number, kobo: number): { x: number; path: string } {
    const x = PAD.left + band.value * i + (band.value - BAR.value) / 2;
    const zero = scale.value.zero;
    const end = scale.value.y(kobo / 100);
    const h = Math.abs(zero - end);
    const r = Math.min(RADIUS, h);

    if (h < 0.5) {
        return { x, path: '' };
    }

    // Rounded at the value end, square at the baseline.
    const path =
        kobo >= 0
            ? `M${x},${zero} V${end + r} Q${x},${end} ${x + r},${end} H${x + BAR.value - r} Q${x + BAR.value},${end} ${x + BAR.value},${end + r} V${zero} Z`
            : `M${x},${zero} V${end - r} Q${x},${end} ${x + r},${end} H${x + BAR.value - r} Q${x + BAR.value},${end} ${x + BAR.value},${end - r} V${zero} Z`;

    return { x, path };
}

const compact = (naira: number) =>
    Math.abs(naira) >= 1000
        ? `${naira < 0 ? '−' : ''}₦${(Math.abs(naira) / 1000).toLocaleString('en-NG', { maximumFractionDigits: 1 })}K`
        : `${naira < 0 ? '−' : ''}₦${Math.abs(naira)}`;

const weekday = (date: string) =>
    new Intl.DateTimeFormat('en-NG', {
        timeZone: 'UTC',
        weekday: 'short',
    }).format(new Date(`${date}T00:00:00Z`));
const longDay = (date: string) =>
    new Intl.DateTimeFormat('en-NG', {
        timeZone: 'UTC',
        weekday: 'long',
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${date}T00:00:00Z`));

const hover = ref<number | null>(null);
const hovered = computed(() =>
    hover.value === null ? null : props.days[hover.value],
);
</script>

<template>
    <div ref="box" class="relative">
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="h-auto w-full overflow-visible"
            role="img"
            :aria-label="`Profit for the last ${days.length} days`"
            @mouseleave="hover = null"
        >
            <!-- Grid and y axis -->
            <g class="text-[11px]" aria-hidden="true">
                <template v-for="t in scale.ticks" :key="t">
                    <line
                        :x1="PAD.left"
                        :x2="W - PAD.right"
                        :y1="scale.y(t)"
                        :y2="scale.y(t)"
                        :stroke="
                            t === 0
                                ? 'var(--muted-foreground)'
                                : 'var(--border)'
                        "
                        :stroke-opacity="t === 0 ? 0.6 : 1"
                        stroke-width="1"
                    />
                    <text
                        :x="PAD.left - 8"
                        :y="scale.y(t)"
                        text-anchor="end"
                        dominant-baseline="middle"
                        fill="var(--muted-foreground)"
                        class="tabular"
                    >
                        {{ compact(t) }}
                    </text>
                </template>
            </g>

            <!-- Columns -->
            <g>
                <g v-for="(d, i) in days" :key="d.date">
                    <!-- Hit area: the whole column band, bigger than the mark -->
                    <rect
                        :x="PAD.left + band * i"
                        :y="PAD.top"
                        :width="band"
                        :height="plotH"
                        fill="transparent"
                        @mouseenter="hover = i"
                        @click="hover = hover === i ? null : i"
                    />
                    <rect
                        v-if="hover === i"
                        :x="PAD.left + band * i + 2"
                        :y="PAD.top"
                        :width="band - 4"
                        :height="plotH"
                        rx="6"
                        fill="var(--muted)"
                        opacity="0.6"
                        pointer-events="none"
                    />
                    <path
                        v-if="bar(i, d.profit).path"
                        :d="bar(i, d.profit).path"
                        fill="var(--chart-bar)"
                        pointer-events="none"
                    />
                    <text
                        :x="PAD.left + band * i + band / 2"
                        :y="H - 6"
                        text-anchor="middle"
                        class="text-[11px]"
                        :fill="
                            d.date === today
                                ? 'var(--foreground)'
                                : 'var(--muted-foreground)'
                        "
                        :font-weight="d.date === today ? 600 : 400"
                        pointer-events="none"
                    >
                        {{ d.date === today ? 'Today' : weekday(d.date) }}
                    </text>
                    <!-- Label only today's column; the tooltip and table carry the rest -->
                    <text
                        v-if="d.date === today && hover === null"
                        :x="PAD.left + band * i + band / 2"
                        :y="
                            d.profit >= 0
                                ? scale.y(d.profit / 100) - 6
                                : scale.y(d.profit / 100) + 14
                        "
                        text-anchor="middle"
                        class="tabular text-[11px] font-semibold"
                        fill="var(--foreground)"
                        pointer-events="none"
                    >
                        {{ compact(d.profit / 100) }}
                    </text>
                </g>
            </g>
        </svg>

        <div
            v-if="hovered && hover !== null"
            class="pointer-events-none absolute top-0 z-10 w-44 -translate-x-1/2 rounded-xl border bg-popover px-3 py-2 text-xs shadow-lg"
            :style="{
                left: `${((PAD.left + band * hover + band / 2) / W) * 100}%`,
            }"
            role="status"
        >
            <p class="font-semibold">
                {{ hovered.date === today ? 'Today' : longDay(hovered.date) }}
            </p>
            <p class="mt-1 flex justify-between">
                <span class="text-muted-foreground">Profit</span>
                <span class="tabular font-semibold">{{
                    formatNaira(hovered.profit, { trim: true })
                }}</span>
            </p>
            <p class="flex justify-between">
                <span class="text-muted-foreground">Volume</span>
                <span class="tabular">{{
                    formatNaira(hovered.volume, { trim: true })
                }}</span>
            </p>
            <p class="flex justify-between">
                <span class="text-muted-foreground">Entries</span>
                <span class="tabular">{{ hovered.count }}</span>
            </p>
        </div>

        <table class="sr-only">
            <caption>
                Profit, volume and entries per day
            </caption>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Profit</th>
                    <th>Volume</th>
                    <th>Entries</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="d in days" :key="d.date">
                    <td>{{ longDay(d.date) }}</td>
                    <td>{{ formatNaira(d.profit) }}</td>
                    <td>{{ formatNaira(d.volume) }}</td>
                    <td>{{ d.count }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
