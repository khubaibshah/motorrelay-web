<script setup>
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import BackButton from '@/components/BackButton.vue';
import DriverPayoutConnectCard from '@/components/profile/DriverPayoutConnectCard.vue';

const auth = useAuthStore();

const payoutComplete = computed(() => Boolean(
  auth.user?.stripe_payouts_enabled || auth.user?.stripe_onboarding_complete,
));

const verificationItems = computed(() => [
  {
    label: 'Identity and payout account',
    description: 'Stripe securely verifies your identity and payout details in one guided flow.',
    complete: payoutComplete.value,
    status: payoutComplete.value ? 'Complete' : 'Action needed',
  },
  {
    label: 'Driving licence',
    description: 'Add your licence details so MotorRelay can complete the driver checks.',
    complete: false,
    status: 'Next step',
  },
  {
    label: 'Trader insurance',
    description: 'Keep your current trader insurance information available for review.',
    complete: false,
    status: 'Next step',
  },
  {
    label: 'Trade plates (if applicable)',
    description: 'Tell us about trade plates if you use them for vehicle movements.',
    complete: false,
    status: 'Optional',
  },
]);

onMounted(() => {
  if (auth.token) auth.fetchMe().catch(() => null);
});
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-4">
    <div class="flex items-center gap-3">
      <BackButton />
      <span class="text-sm font-semibold text-slate-500">Profile</span>
    </div>

    <section class="tile space-y-2 p-5 md:p-7">
      <p class="eyebrow">Driver verification</p>
      <h1 class="text-2xl font-black text-slate-950 md:text-3xl dark:text-white">Identity &amp; verification</h1>
      <p class="max-w-2xl text-sm leading-6 text-slate-600 dark:text-emerald-100">
        Complete the checks needed to drive on MotorRelay. Stripe handles identity and payout setup securely, while the remaining driver details stay in your MotorRelay profile.
      </p>
    </section>

    <DriverPayoutConnectCard />

    <section class="tile space-y-4 p-5 md:p-7">
      <div>
        <p class="eyebrow">Verification checklist</p>
        <h2 class="mt-1 text-xl font-black text-slate-950 dark:text-white">Keep your driver account ready</h2>
      </div>

      <div class="grid gap-3 md:grid-cols-2">
        <article
          v-for="item in verificationItems"
          :key="item.label"
          class="rounded-2xl border p-4"
          :class="item.complete ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-400/30 dark:bg-emerald-400/10' : 'border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-white/[0.04]'"
        >
          <div class="flex items-start justify-between gap-3">
            <h3 class="font-bold text-slate-900 dark:text-white">{{ item.label }}</h3>
            <span
              class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold"
              :class="item.complete ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-400/20 dark:text-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-white/10 dark:text-emerald-100'"
            >
              {{ item.status }}
            </span>
          </div>
          <p class="mt-2 text-sm leading-5 text-slate-600 dark:text-emerald-100">{{ item.description }}</p>
        </article>
      </div>

      <RouterLink to="/account" class="btn-secondary w-full md:w-auto">
        Update licence and insurance details
      </RouterLink>
    </section>

    <p class="px-1 text-xs leading-5 text-slate-500 dark:text-emerald-100">
      Your identity documents and bank details are handled by Stripe. MotorRelay only receives verification and payout status.
    </p>
  </div>
</template>
