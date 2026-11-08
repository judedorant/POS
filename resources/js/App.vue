<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Catalog from './Catalog.vue'
import Inventory from './Inventory.vue'
import Checkout from './Checkout.vue'
import OfflineSales from './OfflineSales.vue'
import LiveUpdates from './LiveUpdates.vue'
import Reports from './Reports.vue'
import { useSession, api } from './session'
const session = useSession()
const email = ref(''), password = ref(''), error = ref(''), busy = ref(false), loading = ref(true)
const name = ref(''), slug = ref('')
async function run(action: () => Promise<unknown>) {
  busy.value = true; error.value = ''
  try { await action() } catch { error.value = 'The request failed. Check your details and connection, then try again.' }
  finally { busy.value = false }
}
onMounted(async () => { await run(() => session.restore()); loading.value = false })
async function login() { await run(() => session.login(email.value, password.value)); password.value = '' }
async function createTenant() {
  await api.post('/api/v1/tenants', { name: name.value, slug: slug.value, timezone: 'UTC', default_currency: 'USD' })
  await session.loadTenants(); name.value = ''; slug.value = ''
}
</script>
<template>
  <main class="max-w-5xl mx-auto px-6 py-12">
    <header class="flex justify-between items-center border-b border-stone-300 pb-6 mb-12">
      <div><p class="text-xs font-bold uppercase tracking-widest text-teal-700">Commerce workspace</p><h1 class="text-3xl font-semibold mt-2">Tenant POS</h1></div>
      <button v-if="session.user" :disabled="busy" @click="run(() => session.logout())" class="underline">Sign out</button>
    </header>
    <p v-if="error" role="alert" class="mb-6 rounded bg-red-100 p-4 text-red-800">{{ error }}</p>
    <p v-if="loading">Loading your workspace…</p>
    <form v-else-if="!session.user" @submit.prevent="login" class="max-w-md bg-white border border-stone-200 p-8 rounded-xl space-y-5">
      <h2 class="text-2xl font-semibold">Welcome back</h2><p class="text-stone-600">Sign in to access your business.</p>
      <label class="block">Email<input v-model="email" type="email" autocomplete="username" required class="mt-2 block w-full border rounded p-3" /></label>
      <label class="block">Password<input v-model="password" type="password" autocomplete="current-password" required class="mt-2 block w-full border rounded p-3" /></label>
      <button :disabled="busy" class="bg-teal-800 text-white rounded px-6 py-3 disabled:opacity-50">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
    </form>
    <section v-else class="space-y-8">
      <div><h2 class="text-2xl font-semibold">Hello, {{ session.user.name }}</h2><p class="text-stone-600 mt-2">Choose a business to continue.</p></div>
      <div class="grid sm:grid-cols-2 gap-4"><button v-for="tenant in session.tenants" :key="tenant.id" :disabled="busy" @click="run(() => session.select(tenant))" class="text-left rounded-xl border bg-white p-6 hover:border-teal-700"><span class="block text-lg font-semibold">{{ tenant.name }}</span><span class="text-stone-500">{{ tenant.slug }}</span></button></div>
      <div v-if="session.selected" role="status" class="bg-teal-50 border border-teal-200 p-6 rounded-xl"><h3 class="font-semibold">{{ session.selected.name }}</h3><p>Your role: {{ session.role.replaceAll('_', ' ') }}</p><p class="mt-2 text-stone-600">Manage your catalog and warehouse locations below.</p></div>
      <LiveUpdates v-if="session.selected" :key="session.selected.id" :tenant-id="session.selected.id" />
      <OfflineSales v-if="session.selected && ['admin','cashier'].includes(session.role)" :key="session.selected.id" :tenant-id="session.selected.id" :user-id="session.user.id" :currency="session.selected.default_currency" :role="session.role" />
      <Checkout v-if="session.selected && ['admin','cashier'].includes(session.role)" :key="session.selected.id" :tenant-id="session.selected.id" :currency="session.selected.default_currency" :user-id="session.user.id" :role="session.role" />
      <Reports v-if="session.selected && ['admin','inventory_manager'].includes(session.role)" :key="session.selected.id" :tenant-id="session.selected.id" :role="session.role" :timezone="session.selected.timezone" />
      <Catalog v-if="session.selected" :key="session.selected.id" :tenant-id="session.selected.id" :role="session.role" :currency="session.selected.default_currency" />
      <Inventory v-if="session.selected" :key="session.selected.id" :tenant-id="session.selected.id" :role="session.role" />
      <form @submit.prevent="run(createTenant)" class="max-w-md space-y-4 border-t pt-6"><h3 class="font-semibold">Create a business</h3><label class="block">Business name<input v-model="name" required maxlength="120" class="block w-full border rounded p-2 bg-white" /></label><label class="block">Unique identifier<input v-model="slug" required pattern="[A-Za-z0-9_-]+" maxlength="80" class="block w-full border rounded p-2 bg-white" /></label><p class="text-sm text-stone-500">Initial defaults: USD and UTC.</p><button :disabled="busy" class="bg-teal-800 text-white rounded px-4 py-2">Create business</button></form>
    </section>
  </main>
</template>
