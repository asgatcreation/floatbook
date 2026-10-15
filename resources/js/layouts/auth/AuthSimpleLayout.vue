<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BookCheck,
    Check,
    CircleCheck,
    ShieldCheck,
    WifiOff,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { home, login, register } from '@/routes';

const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        /** Which brand panel to show beside the form on wide screens. */
        panel?: 'login' | 'register' | 'plain';
    }>(),
    { panel: 'plain' },
);

const gets = [
    'Moniepoint, OPay and Palmpay ready, and any bank POS you add',
    'Starter charges and fees you can change any time',
    'Each agent with their own login, outlets and terminals',
    'Works on any phone, even when the network drops',
];

const steps = ['Create account', 'Confirm email', 'Add your outlet'];

const trust = [
    { icon: ShieldCheck, text: 'Two-factor login and passkeys' },
    { icon: BookCheck, text: 'Double-entry ledger' },
    { icon: WifiOff, text: 'Works offline' },
];
</script>

<template>
    <div
        class="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]"
    >
        <!-- Brand panel (wide screens only) -->
        <aside
            class="relative hidden overflow-hidden bg-[#10141b] px-12 py-10 text-white lg:sticky lg:top-0 lg:flex lg:h-svh lg:flex-col"
        >
            <!-- Soft gold glow and a faint dot grid -->
            <div
                class="pointer-events-none absolute -top-40 -right-40 size-[34rem] rounded-full bg-[radial-gradient(closest-side,rgb(232_163_23/0.20),transparent)]"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute inset-0 [background-image:radial-gradient(rgb(255_255_255/0.06)_1px,transparent_1px)] [mask-image:linear-gradient(to_bottom,black,transparent_85%)] [background-size:22px_22px]"
                aria-hidden="true"
            />

            <Link
                :href="home()"
                class="relative flex w-fit items-center gap-2.5"
            >
                <AppLogoIcon class="size-9" />
                <span class="text-xl font-bold tracking-tight">FloatBook</span>
            </Link>

            <!-- Login: a counter receipt -->
            <div
                v-if="props.panel === 'login'"
                class="relative my-auto py-10"
                aria-hidden="true"
            >
                <p
                    class="max-w-sm text-[2rem] leading-tight font-bold tracking-tight text-balance"
                >
                    Every naira at the counter, accounted for.
                </p>
                <p class="mt-3 max-w-sm text-white/60">
                    Pick up where your counter left off.
                </p>

                <div class="relative mt-12 w-fit">
                    <div
                        class="w-72 -rotate-2 rounded-t-xl bg-[#fbfaf5] [mask-image:linear-gradient(-45deg,transparent_6px,black_6px),linear-gradient(45deg,transparent_6px,black_6px)] [mask-composite:intersect] [mask-size:12px_100%] [mask-position:bottom] [mask-repeat:repeat-x] px-5 pt-5 pb-6 font-mono text-[12px] text-[#10141b] shadow-2xl shadow-black/40"
                    >
                        <div class="text-center">
                            <p class="font-sans text-sm font-bold">Ketu Main</p>
                            <p class="text-[#59616c]">
                                Terminal 1 · Moniepoint
                            </p>
                        </div>
                        <div
                            class="my-3 border-t border-dashed border-[#10141b]/30"
                        />
                        <dl class="grid gap-1.5">
                            <div class="flex justify-between">
                                <dt>Withdrawal</dt>
                                <dd class="tabular font-bold">₦10,000.00</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Charge</dt>
                                <dd class="tabular">₦200.00</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Agent</dt>
                                <dd>Sola A.</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Ref</dt>
                                <dd class="tabular">220944718800</dd>
                            </div>
                        </dl>
                        <div
                            class="my-3 border-t border-dashed border-[#10141b]/30"
                        />
                        <p
                            class="flex items-center justify-center gap-1.5 font-sans font-semibold text-[#12723a]"
                        >
                            <CircleCheck class="size-4" /> Recorded 9:12 am
                        </p>
                    </div>

                    <span
                        class="absolute -top-4 -right-28 inline-flex items-center gap-1.5 rounded-full bg-[#4ade80]/15 px-3 py-1.5 text-xs font-semibold text-[#4ade80] ring-1 ring-[#4ade80]/30 backdrop-blur"
                    >
                        <Check class="size-3.5" /> Drawer balanced
                    </span>
                    <span
                        class="absolute -right-32 bottom-10 rounded-xl bg-white/[0.07] px-3 py-2 text-xs ring-1 ring-white/10 backdrop-blur"
                    >
                        <span class="block text-white/50"
                            >Today, all outlets</span
                        >
                        <span class="tabular font-semibold text-[#4ade80]"
                            >+₦38,450 profit</span
                        >
                    </span>
                </div>
            </div>

            <!-- Register: what you get -->
            <div
                v-else-if="props.panel === 'register'"
                class="relative my-auto max-w-md py-10"
            >
                <p
                    class="text-[2rem] leading-tight font-bold tracking-tight text-balance"
                >
                    Your counter, running properly by this evening.
                </p>

                <ol
                    class="mt-8 flex items-center gap-2 text-xs"
                    aria-label="Steps"
                >
                    <li
                        v-for="(step, i) in steps"
                        :key="step"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="grid size-6 place-items-center rounded-full font-bold"
                            :class="
                                i === 0
                                    ? 'bg-gold text-gold-foreground'
                                    : 'bg-white/10 text-white/70'
                            "
                            >{{ i + 1 }}</span
                        >
                        <span
                            :class="i === 0 ? 'text-white' : 'text-white/55'"
                            >{{ step }}</span
                        >
                        <span
                            v-if="i < steps.length - 1"
                            class="h-px w-5 bg-white/20"
                            aria-hidden="true"
                        />
                    </li>
                </ol>

                <ul class="mt-8 grid gap-3">
                    <li
                        v-for="item in gets"
                        :key="item"
                        class="flex items-start gap-3 rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm text-white/85"
                    >
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-gold"
                            aria-hidden="true"
                        />
                        {{ item }}
                    </li>
                </ul>
            </div>

            <!-- Everything else: a quiet line -->
            <div v-else class="relative my-auto max-w-sm py-10">
                <p
                    class="text-[2rem] leading-tight font-bold tracking-tight text-balance"
                >
                    Every naira at the counter, accounted for.
                </p>
            </div>

            <ul
                class="relative flex flex-wrap gap-x-6 gap-y-2 border-t border-white/10 pt-6 text-xs text-white/55"
            >
                <li
                    v-for="item in trust"
                    :key="item.text"
                    class="flex items-center gap-1.5"
                >
                    <component
                        :is="item.icon"
                        class="size-3.5 text-gold"
                        aria-hidden="true"
                    />
                    {{ item.text }}
                </li>
            </ul>
        </aside>

        <!-- Form side -->
        <div class="flex min-h-svh flex-col">
            <header
                class="flex items-center justify-between px-4 pt-5 sm:px-8 lg:pt-8"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2.5 lg:hidden"
                >
                    <AppLogoIcon class="size-8" />
                    <span class="text-lg font-bold tracking-tight"
                        >FloatBook</span
                    >
                </Link>
                <Link
                    :href="home()"
                    class="hidden items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground lg:inline-flex"
                >
                    <ArrowLeft class="size-4" /> Back to home
                </Link>
                <p
                    v-if="props.panel === 'login'"
                    class="text-sm text-muted-foreground"
                >
                    <span class="hidden sm:inline">New here? </span>
                    <Link
                        :href="register()"
                        class="font-semibold text-foreground underline-offset-4 hover:underline"
                        >Create an account</Link
                    >
                </p>
                <p
                    v-else-if="props.panel === 'register'"
                    class="text-sm text-muted-foreground"
                >
                    <span class="hidden sm:inline">Have an account? </span>
                    <Link
                        :href="login()"
                        class="font-semibold text-foreground underline-offset-4 hover:underline"
                        >Log in</Link
                    >
                </p>
            </header>

            <main
                class="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-8"
            >
                <div
                    class="w-full max-w-[26rem] [&_input:not([type=checkbox])]:h-11 [&_input:not([type=checkbox])]:rounded-xl [&_input:not([type=checkbox])]:text-base sm:[&_input:not([type=checkbox])]:text-sm"
                >
                    <div v-if="title || description" class="mb-8 space-y-2">
                        <h1
                            class="text-[1.75rem] leading-tight font-bold tracking-tight sm:text-3xl"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-[15px] leading-relaxed text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </main>

            <footer
                class="flex items-center justify-center gap-1.5 px-4 pb-6 text-xs text-muted-foreground"
            >
                <ShieldCheck class="size-3.5" aria-hidden="true" />
                Your books are private to your business.
            </footer>
        </div>
        <Toaster />
    </div>
</template>
