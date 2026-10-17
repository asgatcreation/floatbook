<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookCheck,
    FileSpreadsheet,
    History,
    LogIn,
    LockKeyhole,
    RotateCcw,
    Users,
    Wallet,
    WifiOff,
    Zap,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import EntryPreview from '@/components/marketing/EntryPreview.vue';
import { dashboard, login, privacy, register, terms } from '@/routes';
import { login as demoLogin } from '@/routes/demo';

defineProps<{
    demoRoles: {
        key: string;
        name: string;
        role: string;
        description: string;
    }[];
}>();

const features = [
    {
        icon: Zap,
        title: 'Record in seconds',
        body: 'Pick the type, tap the amount, save. Charges, provider fees and your profit are worked out as you type, from your own price list.',
    },
    {
        icon: Wallet,
        title: 'Cash and wallet float',
        body: 'Every outlet’s drawer and Moniepoint, OPay and Palmpay wallets update with each entry. Top-ups and transfers between outlets need approval.',
    },
    {
        icon: RotateCcw,
        title: 'Failed and pending, followed up',
        body: 'A failed or pending transaction opens a case with a 72-hour target, notes and ticket numbers, so no customer’s money is forgotten.',
    },
    {
        icon: FileSpreadsheet,
        title: 'Check against the statement',
        body: 'Upload the provider’s CSV or Excel statement. FloatBook shows what matches, what disagrees and what nobody recorded.',
    },
    {
        icon: Users,
        title: 'Staff who see only their part',
        body: 'Owners, supervisors, agents and accountants each get the right screens and the right outlets. A PIN locks the counter phone.',
    },
    {
        icon: WifiOff,
        title: 'Keeps working offline',
        body: 'When the network drops, entries are kept on the phone and sent once it’s back, never twice. Install it on the home screen like an app.',
    },
];

const steps = [
    {
        title: 'Set up your business',
        body: 'Add your outlets and providers. Starter charge rules are filled in for you, and you can change them any time.',
    },
    {
        title: 'Invite your agents',
        body: 'Each agent gets their own login, tied to the outlets they work at. Every entry carries their name.',
    },
    {
        title: 'Close each day knowing',
        body: 'See the cash and float each outlet should have, follow up failed transactions, and check the provider’s statement.',
    },
];

const safeguards = [
    {
        icon: BookCheck,
        text: 'Double-entry ledger: money in and money out always add up, checked by the database itself.',
    },
    {
        icon: History,
        text: 'Every important change is in the audit log, with who did it, when and the before and after values.',
    },
    {
        icon: LockKeyhole,
        text: 'Two-factor login, passkeys, and a PIN lock for shared counter phones.',
    },
];
</script>

<template>
    <Head title="The back office for POS businesses">
        <meta
            name="description"
            content="FloatBook helps POS and agent-banking businesses record transactions, balance cash and wallet float, follow up reversals and check provider statements."
        />
    </Head>

    <div class="min-h-svh bg-background text-foreground">
        <!-- Top bar -->
        <header
            class="sticky top-0 z-30 border-b border-border/60 bg-background/85 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
                aria-label="Main"
            >
                <Link :href="'/'" class="flex items-center gap-2.5">
                    <AppLogoIcon class="size-8" />
                    <span class="text-lg font-bold tracking-tight"
                        >FloatBook</span
                    >
                </Link>

                <div
                    class="hidden items-center gap-7 text-sm text-muted-foreground md:flex"
                >
                    <a href="#features" class="hover:text-foreground"
                        >Features</a
                    >
                    <a href="#how" class="hover:text-foreground"
                        >How it works</a
                    >
                    <a href="#trust" class="hover:text-foreground"
                        >Safeguards</a
                    >
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90"
                    >
                        Open FloatBook <ArrowRight class="size-4" />
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex h-10 items-center rounded-xl px-3 text-sm font-semibold hover:bg-muted"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex h-10 items-center rounded-xl bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90"
                        >
                            Get started
                        </Link>
                    </template>
                </div>
            </nav>
        </header>

        <main>
            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 h-[520px] bg-[radial-gradient(60%_60%_at_70%_20%,color-mix(in_srgb,var(--gold)_18%,transparent),transparent)]"
                    aria-hidden="true"
                />
                <div
                    class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 pt-14 pb-20 sm:px-6 lg:grid-cols-[1.1fr_0.9fr] lg:pt-20 lg:pb-28"
                >
                    <div>
                        <p
                            class="inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1 text-xs font-semibold text-muted-foreground"
                        >
                            <span class="size-1.5 rounded-full bg-gold" />
                            For POS and agent-banking businesses in Nigeria
                        </p>
                        <h1
                            class="mt-5 text-4xl leading-[1.08] font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl"
                        >
                            Every naira at the counter,
                            <span
                                class="underline decoration-gold decoration-[0.12em] underline-offset-[0.14em]"
                                >accounted for.</span
                            >
                        </h1>
                        <p
                            class="mt-5 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground"
                        >
                            FloatBook replaces the notebook and the WhatsApp
                            group. Agents record withdrawals and transfers in
                            seconds; you see every outlet’s cash, wallet float
                            and profit without being there.
                        </p>
                        <div
                            v-if="$page.props.auth.user"
                            class="mt-8 flex flex-wrap gap-3"
                        >
                            <Link
                                :href="dashboard()"
                                class="inline-flex h-12 items-center gap-2 rounded-xl bg-gold px-6 text-base font-bold text-gold-foreground shadow-sm hover:brightness-95"
                            >
                                Open FloatBook
                                <ArrowRight class="size-4" />
                            </Link>
                        </div>
                        <div v-else class="mt-8 flex flex-wrap gap-3">
                            <Link
                                :href="register()"
                                class="inline-flex h-12 items-center gap-2 rounded-xl bg-gold px-6 text-base font-bold text-gold-foreground shadow-sm hover:brightness-95"
                            >
                                Set up your business
                                <ArrowRight class="size-4" />
                            </Link>
                            <Link
                                :href="login()"
                                class="inline-flex h-12 items-center rounded-xl border bg-card px-6 text-base font-semibold hover:bg-muted"
                            >
                                Log in
                            </Link>
                        </div>
                        <!-- Look around without making an account -->
                        <div
                            v-if="demoRoles.length && !$page.props.auth.user"
                            class="mt-8 rounded-2xl border bg-card p-4"
                        >
                            <p class="text-sm font-semibold">
                                Or look around the demo business
                            </p>
                            <p class="mt-0.5 text-sm text-muted-foreground">
                                Two weeks of real-looking trading at two
                                outlets. Nothing you do there is kept: it is
                                rebuilt each night.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Form
                                    v-for="person in demoRoles"
                                    :key="person.key"
                                    v-bind="demoLogin.form(person.key)"
                                    #default="{ processing }"
                                >
                                    <button
                                        type="submit"
                                        class="inline-flex h-10 items-center gap-2 rounded-xl border px-3.5 text-sm font-medium hover:bg-muted disabled:opacity-60"
                                        :disabled="processing"
                                        :title="person.description"
                                    >
                                        <LogIn
                                            class="size-4 text-muted-foreground"
                                        />
                                        {{ person.role }}
                                    </button>
                                </Form>
                            </div>
                        </div>

                        <ul
                            class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground"
                        >
                            <li class="flex items-center gap-2">
                                <span class="size-1.5 rounded-full bg-gain" />
                                Works on any phone
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="size-1.5 rounded-full bg-gain" />
                                Keeps working offline
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="size-1.5 rounded-full bg-gain" />
                                Moniepoint, OPay and Palmpay
                            </li>
                        </ul>
                    </div>

                    <div class="relative mx-auto w-fit lg:mr-0 lg:ml-auto">
                        <EntryPreview />
                        <!-- Owner's view, floating beside the phone -->
                        <div
                            class="absolute top-[38%] -left-44 hidden w-60 rounded-2xl border bg-card p-4 shadow-xl shadow-black/10 sm:block"
                            aria-hidden="true"
                        >
                            <p class="text-xs text-muted-foreground">
                                Today, all outlets
                            </p>
                            <p class="tabular mt-1 text-2xl font-bold">
                                ₦38,450
                                <span class="text-sm font-semibold text-gain"
                                    >profit</span
                                >
                            </p>
                            <div class="mt-3 grid gap-1.5 text-xs">
                                <div class="flex justify-between">
                                    <span>Ketu Main</span
                                    ><span class="tabular font-semibold"
                                        >₦21,300</span
                                    >
                                </div>
                                <div class="flex justify-between">
                                    <span>Ojota Junction</span
                                    ><span class="tabular font-semibold"
                                        >₦17,150</span
                                    >
                                </div>
                                <div
                                    class="mt-1 flex items-center justify-between rounded-lg bg-warning-soft px-2 py-1 text-warning"
                                >
                                    <span>1 reversal waiting</span>
                                    <span class="font-semibold">₦20,000</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section
                id="features"
                class="scroll-mt-20 border-t bg-card"
                aria-labelledby="features-heading"
            >
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                    <div class="max-w-2xl">
                        <h2
                            id="features-heading"
                            class="text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            Built around a real day at the counter
                        </h2>
                        <p class="mt-3 text-lg text-muted-foreground">
                            From the first withdrawal in the morning to the cash
                            count at closing time.
                        </p>
                    </div>
                    <div
                        class="mt-12 grid gap-px overflow-hidden rounded-2xl border bg-border sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <article
                            v-for="feature in features"
                            :key="feature.title"
                            class="bg-card p-6"
                        >
                            <span
                                class="grid size-10 place-items-center rounded-xl bg-muted"
                            >
                                <component
                                    :is="feature.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <h3 class="mt-4 font-semibold">
                                {{ feature.title }}
                            </h3>
                            <p
                                class="mt-1.5 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ feature.body }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section
                id="how"
                class="scroll-mt-20"
                aria-labelledby="how-heading"
            >
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                    <h2
                        id="how-heading"
                        class="text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        Running in an afternoon
                    </h2>
                    <ol class="mt-10 grid gap-6 md:grid-cols-3">
                        <li
                            v-for="(step, i) in steps"
                            :key="step.title"
                            class="rounded-2xl border bg-card p-6"
                        >
                            <span
                                class="tabular grid size-9 place-items-center rounded-full bg-primary text-sm font-bold text-primary-foreground"
                                >{{ i + 1 }}</span
                            >
                            <h3 class="mt-4 font-semibold">{{ step.title }}</h3>
                            <p
                                class="mt-1.5 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ step.body }}
                            </p>
                        </li>
                    </ol>

                    <aside
                        class="mt-8 rounded-2xl border border-dashed p-5 text-sm leading-relaxed text-muted-foreground"
                    >
                        <strong class="text-foreground">An honest note:</strong>
                        FloatBook does not connect to your POS terminals. Agents
                        record each transaction (it takes a few seconds), and
                        you upload the provider’s statement to catch anything
                        that was missed.
                    </aside>
                </div>
            </section>

            <!-- Safeguards -->
            <section
                id="trust"
                class="scroll-mt-20 bg-[#10141b] text-white"
                aria-labelledby="trust-heading"
            >
                <div
                    class="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2"
                >
                    <div>
                        <h2
                            id="trust-heading"
                            class="text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            Kept like real books
                        </h2>
                        <p class="mt-3 text-lg text-white/70">
                            Amounts are stored to the kobo, and nothing is ever
                            quietly changed or deleted. A mistake is voided with
                            a reason, and the record stays.
                        </p>
                    </div>
                    <ul class="grid gap-4">
                        <li
                            v-for="item in safeguards"
                            :key="item.text"
                            class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.04] p-5"
                        >
                            <component
                                :is="item.icon"
                                class="mt-0.5 size-5 shrink-0 text-gold"
                                aria-hidden="true"
                            />
                            <p class="text-sm leading-relaxed text-white/85">
                                {{ item.text }}
                            </p>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Closing call to action -->
            <section
                v-if="!$page.props.auth.user"
                class="mx-auto max-w-6xl px-4 py-20 text-center sm:px-6"
            >
                <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">
                    Close today with the numbers you trust
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-lg text-muted-foreground">
                    Set up your outlets, invite your agents and record your
                    first transaction in a few minutes.
                </p>
                <Link
                    :href="register()"
                    class="mt-8 inline-flex h-12 items-center gap-2 rounded-xl bg-gold px-6 text-base font-bold text-gold-foreground shadow-sm hover:brightness-95"
                >
                    Set up your business <ArrowRight class="size-4" />
                </Link>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <div class="flex items-center gap-2">
                    <AppLogoIcon class="size-5" />
                    <span>© {{ new Date().getFullYear() }} FloatBook</span>
                </div>
                <p class="flex gap-4">
                    <Link :href="privacy()" class="hover:text-foreground"
                        >Privacy</Link
                    >
                    <Link :href="terms()" class="hover:text-foreground"
                        >Terms</Link
                    >
                </p>
                <p>
                    Designed and built by Akanji Oluwaseun Gabriel
                    <a
                        href="https://github.com/asgatcreation"
                        class="font-medium text-foreground underline-offset-4 hover:underline"
                        target="_blank"
                        rel="noopener"
                        >(@asgatcreation)</a
                    >
                </p>
            </div>
        </footer>
    </div>
</template>
