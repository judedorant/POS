<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import { api } from './session'
import { useTenantChanges } from './live-updates'
const props = defineProps<{ tenantId: string; role: string; currency: string }>()
type Kind = 'products' | 'categories' | 'warehouses'
interface Row { reorder_point?: string; id: string; name: string; sku?: string; barcode?: string; slug?: string; code?: string; category_id?: string; sale_price_minor?: number; unit?: string; address?: string; is_active: boolean; track_inventory?: boolean }
const kind = ref<Kind>('products'), rows = ref<Row[]>([]), q = ref(''), page = ref(1), last = ref(1)
const busy = ref(false), error = ref(''), editing = ref(false), recordId = ref<string | null>(null)
const form = ref<Record<string, string | number | boolean | null>>({})
const categories = ref<Row[]>([])
const canEdit = computed(() => ['admin', 'inventory_manager'].includes(props.role))
const base = () => `/api/v1/tenants/${props.tenantId}/${kind.value}`
async function run(action: () => Promise<void>) {
 busy.value = true; error.value = ''
 try { await action() } catch(e) {
  error.value = axios.isAxiosError(e) ? Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || e.response?.data?.message || 'Unable to connect. Please retry.' : 'Unable to complete request.'
 } finally { busy.value = false }
}
async function load() { const { data } = await api.get(base(), { params: { q: q.value, page: page.value } }); rows.value = data.data; last.value = data.last_page }
async function change(value: Kind) { kind.value = value; page.value = 1; q.value = ''; editing.value = false; await run(load) }
async function edit(row?: Row) {
 recordId.value = row?.id ?? null
 form.value = { name: row?.name ?? '', is_active: row?.is_active ?? true }
 if(kind.value === 'products') Object.assign(form.value, { sku: row?.sku ?? '', barcode: row?.barcode ?? '', sale_price_minor: row?.sale_price_minor ?? 0, unit: row?.unit ?? 'each', reorder_point: row?.reorder_point ?? '0', category_id: row?.category_id ?? '', track_inventory: row?.track_inventory ?? true })
 if(kind.value === 'categories') form.value.slug = row?.slug ?? ''
 if(kind.value === 'warehouses') Object.assign(form.value, { code: row?.code ?? '', address: row?.address ?? '' })
 editing.value = true
 if(kind.value === 'products') await run(async () => {
  const all: Row[] = []; let next = 1, final = 1
  do { const { data } = await api.get(`/api/v1/tenants/${props.tenantId}/categories`, { params: { page: next } }); all.push(...data.data); final = data.last_page; next++ } while(next <= final)
  categories.value = all
 })
}
async function save() {
 const payload = { ...form.value }
 for (const field of ['barcode','category_id','address']) if(payload[field] === '') payload[field] = null
 if(recordId.value) await api.patch(`${base()}/${recordId.value}`, payload)
 else await api.post(base(), payload)
 editing.value = false; await load()
}
onMounted(() => run(load))
useTenantChanges(props.tenantId,['catalog'],load)
</script>
<template>
 <section class="bg-white rounded-xl border border-stone-200 p-6 space-y-5">
  <div class="flex flex-wrap justify-between gap-4"><nav aria-label="Catalog sections" class="flex gap-3"><button v-for="tab in (['products','categories','warehouses'] as const)" :key="tab" :disabled="busy" :aria-pressed="kind===tab" @click="change(tab)" :class="kind===tab ? 'font-bold text-teal-800 underline' : 'text-stone-500'">{{ tab }}</button></nav><button v-if="canEdit" :disabled="busy" @click="edit()" class="text-teal-800 font-semibold">+ Add {{ kind === 'categories' ? 'category' : kind.slice(0,-1) }}</button></div>
  <p v-if="error" role="alert" class="bg-red-50 text-red-800 p-3 rounded">{{ error }}</p>
  <form @submit.prevent="page=1; run(load)" class="flex gap-2"><label class="grow"><span class="sr-only">Search catalog</span><input v-model="q" maxlength="160" placeholder="Search name, exact SKU or barcode" class="border rounded p-2 w-full" /></label><button :disabled="busy" class="border px-4 rounded">Search</button></form>
  <form v-if="editing" @submit.prevent="run(save)" class="bg-stone-50 p-4 rounded space-y-3">
   <h3 class="font-semibold">{{ recordId ? 'Edit' : 'Create' }} {{ kind }}</h3>
   <label v-for="field in Object.keys(form).filter(f => !['is_active','track_inventory','unit','category_id'].includes(f))" :key="field" class="block text-sm">{{ field === 'sale_price_minor' ? `Price in minor units (${currency})` : field.replaceAll('_',' ') }}
    <input v-model="form[field]" :type="field === 'sale_price_minor' ? 'number' : 'text'" :min="field === 'sale_price_minor' ? 0 : undefined" :step="field === 'sale_price_minor' ? 1 : undefined" :required="['name','sku','slug','code','sale_price_minor'].includes(field)" class="block border rounded p-2 w-full bg-white" />
   </label>
   <label v-if="kind==='products'" class="block">Category <select v-model="form.category_id" class="border rounded p-2"><option value="">Uncategorized</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}{{ category.is_active ? '' : ' (archived)' }}</option></select></label>
   <label v-if="kind==='products'" class="block">Unit <select v-model="form.unit" class="border rounded p-2"><option v-for="unit in ['each','kg','g','litre','metre']" :key="unit">{{ unit }}</option></select></label>
   <label class="block"><input type="checkbox" v-model="form.is_active" /> Active</label>
   <label v-if="kind==='products'" class="block"><input type="checkbox" v-model="form.track_inventory" /> Track inventory</label>
   <div class="flex gap-4"><button :disabled="busy" class="bg-teal-800 text-white px-4 py-2 rounded">Save</button><button type="button" :disabled="busy" @click="editing=false">Cancel</button></div>
  </form>
  <p v-if="busy" role="status">Loading…</p>
  <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="py-3">Name</th><th>Identifier</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="row in rows" :key="row.id" class="border-b"><td class="py-4">{{ row.name }}<span v-if="kind==='products'" class="block text-stone-500">{{ row.sale_price_minor }} minor units · {{ currency }}</span></td><td>{{ row.sku || row.slug || row.code }}</td><td>{{ row.is_active ? 'Active' : 'Archived' }}</td><td><button v-if="canEdit" :disabled="busy" @click="edit(row)" class="text-teal-800 underline">Edit</button></td></tr></tbody></table></div>
  <p v-if="!busy && rows.length===0" class="text-stone-500">No matching records.</p>
  <div class="flex justify-between"><button :disabled="busy || page<=1" @click="page--; run(load)">Previous</button><span>Page {{ page }} of {{ last }}</span><button :disabled="busy || page>=last" @click="page++; run(load)">Next</button></div>
 </section>
</template>
