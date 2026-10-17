<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home, privacy, terms } from '@/routes';

/**
 * Privacy and Terms. Written in plain English, with the detail a POS business
 * actually needs: whose data this is, where it lives and what happens if they leave.
 */
const props = defineProps<{
    page: 'privacy' | 'terms';
    updated: string;
    contact: string;
}>();

const isPrivacy = props.page === 'privacy';
</script>

<template>
    <Head :title="isPrivacy ? 'Privacy' : 'Terms'" />

    <div class="min-h-svh bg-background">
        <header class="border-b">
            <div
                class="mx-auto flex h-16 max-w-3xl items-center justify-between gap-4 px-4 sm:px-6"
            >
                <Link :href="home()" class="flex items-center gap-2.5">
                    <AppLogoIcon class="size-8" />
                    <span class="text-lg font-bold tracking-tight"
                        >FloatBook</span
                    >
                </Link>
                <Link
                    :href="home()"
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" /> Back to home
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
            <nav class="mb-8 flex gap-2 text-sm" aria-label="Legal pages">
                <Link
                    :href="privacy()"
                    class="rounded-full border px-3 py-1.5 font-medium"
                    :class="
                        isPrivacy
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    >Privacy</Link
                >
                <Link
                    :href="terms()"
                    class="rounded-full border px-3 py-1.5 font-medium"
                    :class="
                        !isPrivacy
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    >Terms</Link
                >
            </nav>

            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
                {{ isPrivacy ? 'Privacy' : 'Terms of use' }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Last updated {{ updated }}
            </p>

            <!-- ============================ PRIVACY ============================ -->
            <div
                v-if="isPrivacy"
                class="mt-10 flex flex-col gap-8 text-[15px] leading-relaxed"
            >
                <section>
                    <h2 class="mb-2 text-lg font-semibold">
                        The short version
                    </h2>
                    <p class="text-muted-foreground">
                        Your books are yours. FloatBook stores what your
                        business records so it can show it back to you. We do
                        not sell it, we do not share it with other businesses,
                        and we never see your customers' money.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">What is stored</h2>
                    <ul class="grid gap-2 text-muted-foreground">
                        <li>
                            <strong class="text-foreground"
                                >Your account:</strong
                            >
                            name, email address, a scrambled version of your
                            password, and (if you set one) a scrambled version
                            of your PIN. The real password and PIN are never
                            stored.
                        </li>
                        <li>
                            <strong class="text-foreground"
                                >Your business:</strong
                            >
                            outlets, terminals, staff and their roles, charge
                            rules and settings.
                        </li>
                        <li>
                            <strong class="text-foreground">Your books:</strong>
                            transactions, float movements, expenses, end-of-day
                            counts and the ledger behind them.
                        </li>
                        <li>
                            <strong class="text-foreground"
                                >Customer details you type:</strong
                            >
                            a name, a phone number or a reference, when you
                            choose to add one to an entry. Only add what you
                            need.
                        </li>
                        <li>
                            <strong class="text-foreground"
                                >A record of changes:</strong
                            >
                            who did what and when, including the device and
                            network address used to sign in. This is what makes
                            the audit log trustworthy.
                        </li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Who can see it</h2>
                    <p class="text-muted-foreground">
                        Only people you invite to your business, and only the
                        parts their role allows. One business can never see
                        another's data: every record carries its business and
                        the database refuses anything else. The people who run
                        FloatBook can reach the database to keep the service
                        working or to help you with a problem you report.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Where it lives</h2>
                    <p class="text-muted-foreground">
                        On servers run by our hosting and database providers,
                        which may be outside Nigeria. Everything travels over an
                        encrypted connection, and the database is encrypted
                        where it is stored. Email (sign-in codes, invitations,
                        your daily summary) is sent through an email provider.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">
                        How long it is kept
                    </h2>
                    <p class="text-muted-foreground">
                        For as long as your business uses FloatBook. Money
                        records are never edited or deleted in place: a mistake
                        is voided or reversed and both sides stay, which is what
                        makes the books reliable. If you close your business
                        account, ask us and we will delete it; backups are
                        overwritten within 30 days.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Your choices</h2>
                    <ul class="grid gap-2 text-muted-foreground">
                        <li>
                            Export your figures at any time from Reports (Excel
                            or CSV).
                        </li>
                        <li>
                            Turn off the daily summary email under Settings →
                            Business.
                        </li>
                        <li>
                            Ask for a copy of your data, or for it to be
                            deleted.
                        </li>
                        <li>
                            Sign out of other devices under Settings → Devices,
                            and turn on two-factor sign-in under Settings →
                            Security.
                        </li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Cookies</h2>
                    <p class="text-muted-foreground">
                        FloatBook sets a cookie to keep you signed in and one to
                        protect forms from being submitted by another site. That
                        is all. There is no advertising and no third-party
                        tracking.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">The demo</h2>
                    <p class="text-muted-foreground">
                        The demo business is made-up data. Anything typed there
                        is wiped when it is rebuilt each night, so please don't
                        put real customer details into it.
                    </p>
                </section>
            </div>

            <!-- ============================= TERMS ============================= -->
            <div
                v-else
                class="mt-10 flex flex-col gap-8 text-[15px] leading-relaxed"
            >
                <section>
                    <h2 class="mb-2 text-lg font-semibold">
                        What FloatBook is
                    </h2>
                    <p class="text-muted-foreground">
                        A record-keeping tool for POS and agent-banking
                        businesses. You type in what happened at your counter
                        and FloatBook keeps the books, works out your charges
                        and profit, and shows you where your float is.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">What it is not</h2>
                    <p class="text-muted-foreground">
                        FloatBook does not move money and is not connected to
                        Moniepoint, OPay, Palmpay, any bank or any POS terminal.
                        It cannot send, receive or reverse a transaction. When a
                        customer is waiting on a reversal, that is between you
                        and your provider; FloatBook only helps you keep track
                        of it.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Your part</h2>
                    <ul class="grid gap-2 text-muted-foreground">
                        <li>
                            What you record is your responsibility. FloatBook
                            can only be as right as what is typed in.
                        </li>
                        <li>
                            Keep your password and PIN to yourself, and give
                            each staff member their own account.
                        </li>
                        <li>
                            Only record customer details you are allowed to
                            keep, and only what you need.
                        </li>
                        <li>
                            Use it lawfully, and don't try to reach another
                            business's data or break the service.
                        </li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">
                        Accounts and staff
                    </h2>
                    <p class="text-muted-foreground">
                        The owner controls who is invited and what each role may
                        do. Removing someone's access stops them straight away,
                        but what they already recorded stays in the books, as it
                        must.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Availability</h2>
                    <p class="text-muted-foreground">
                        We try to keep FloatBook running, but it is offered as
                        it is, without a guarantee of uptime. The agent screen
                        keeps working without a network and sends entries when
                        it returns. Keep your own export of anything you can't
                        afford to lose.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Money and loss</h2>
                    <p class="text-muted-foreground">
                        FloatBook is a record of your business, not an
                        accountant, a bank or financial advice. We are not
                        liable for money lost through a mistyped entry, a
                        provider's failure, a staff member's actions, or a
                        decision made from these figures. Check your books
                        against your provider's statements.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Ending it</h2>
                    <p class="text-muted-foreground">
                        You can stop using FloatBook whenever you like; export
                        your figures first. We may suspend an account that is
                        being used to harm others or the service, and will say
                        why where we can.
                    </p>
                </section>

                <section>
                    <h2 class="mb-2 text-lg font-semibold">Changes</h2>
                    <p class="text-muted-foreground">
                        If these terms change in a way that matters, the date at
                        the top changes and we will say so in the app.
                    </p>
                </section>
            </div>

            <section class="mt-10 rounded-2xl border bg-card p-5 text-sm">
                <h2 class="font-semibold">Questions</h2>
                <p class="mt-1 text-muted-foreground">
                    Email
                    <a
                        :href="`mailto:${contact}`"
                        class="font-medium text-foreground underline underline-offset-4"
                        >{{ contact }}</a
                    >. FloatBook is built and run by Akanji Oluwaseun Gabriel,
                    in Nigeria.
                </p>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-3xl flex-col gap-2 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <span>© {{ new Date().getFullYear() }} FloatBook</span>
                <span class="flex gap-4">
                    <Link :href="privacy()" class="hover:text-foreground"
                        >Privacy</Link
                    >
                    <Link :href="terms()" class="hover:text-foreground"
                        >Terms</Link
                    >
                </span>
            </div>
        </footer>
    </div>
</template>
