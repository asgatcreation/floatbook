<script setup lang="ts">
/**
 * A static picture of the agent's entry screen for the landing page, drawn with the
 * app's own styles so it stays sharp and follows light and dark mode.
 */
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '00', '0', '⌫'];
</script>

<template>
    <div
        class="relative mx-auto w-[280px] rounded-[2.2rem] border-[6px] border-[#1c222c] bg-card p-4 shadow-2xl shadow-black/20 dark:border-[#2a313d]"
        aria-hidden="true"
    >
        <div class="mx-auto mb-3 h-1.5 w-16 rounded-full bg-muted" />

        <div class="flex items-center justify-between text-[11px]">
            <span class="font-semibold">Ketu Main</span>
            <span
                class="rounded-full bg-gain-soft px-2 py-0.5 font-semibold text-gain"
                >Synced</span
            >
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
            <div class="rounded-xl bg-muted p-2.5">
                <p class="text-[10px] text-muted-foreground">Cash in drawer</p>
                <p class="tabular text-sm font-bold">₦483,400</p>
            </div>
            <div class="rounded-xl bg-muted p-2.5">
                <p class="text-[10px] text-muted-foreground">Moniepoint</p>
                <p class="tabular text-sm font-bold">₦791,405</p>
            </div>
        </div>

        <div class="mt-3 flex gap-1.5 text-[10px] font-semibold">
            <span
                class="rounded-full bg-primary px-2.5 py-1 text-primary-foreground"
                >Withdraw</span
            >
            <span class="rounded-full bg-muted px-2.5 py-1">Transfer</span>
            <span class="rounded-full bg-muted px-2.5 py-1">Airtime</span>
        </div>

        <div class="mt-4 text-center">
            <p class="tabular text-3xl font-bold tracking-tight">₦10,000</p>
            <p class="mt-1 text-[11px] text-muted-foreground">
                Charge <span class="font-semibold text-foreground">₦200</span>
                · Profit
                <span class="font-semibold text-gain">₦150</span>
            </p>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-1.5">
            <span
                v-for="key in keys"
                :key="key"
                class="grid h-9 place-items-center rounded-lg bg-muted text-sm font-semibold"
                >{{ key }}</span
            >
        </div>

        <div
            class="mt-3 grid h-10 place-items-center rounded-xl bg-gold text-sm font-bold text-gold-foreground"
        >
            Save withdrawal
        </div>
    </div>
</template>
