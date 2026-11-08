<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { api } from './session'
import { useTenantChanges } from './live-updates'
const props=defineProps<{tenantId:string;role:string}>()
interface Option {id:string;name:string;is_active:boolean;track_inventory?:boolean}
interface Row {id:string;product_name:string;warehouse_name:string;unit:string;on_hand?:string;reserved?:string;quantity_delta?:string;kind?:string;reason?:string}
const products=ref<Option[]>([]), warehouses=ref<Option[]>([]), rows=ref<Row[]>([])
const view=ref('stocks'),page=ref(1),last=ref(1),busy=ref(false),error=ref(''),notice=ref('')
const filter=ref(''),kind=ref('adjustment'),product=ref(''),source=ref(''),destination=ref(''),quantity=ref(''),reason=ref('')
const pending=ref<Record<string,string>|null>(null)
const canEdit=computed(()=>['admin','inventory_manager'].includes(props.role))
const base=`/api/v1/tenants/${props.tenantId}`
async function run(fn:()=>Promise<void>) {busy.value=true;error.value='';try{await fn()}catch(e){error.value=axios.isAxiosError(e)?Object.values(e.response?.data?.errors??{}).flat().join(' ')||e.response?.data?.message||'Connection lost. Retry the same operation.':'Request failed.'}finally{busy.value=false}}
async function options(resource:string) {const all:Option[]=[];let n=1,end=1;do{const {data}=await api.get(`${base}/${resource}`,{params:{page:n,active:1}});all.push(...data.data);end=data.last_page;n++}while(n<=end);return all}
async function load(){const {data}=await api.get(`${base}/inventory/${view.value}`,{params:{page:page.value,warehouse_id:filter.value||undefined}});rows.value=data.data;last.value=data.last_page}
async function submit(){
 if(!pending.value) pending.value={operation_id:crypto.randomUUID(),kind:kind.value,product_id:product.value,warehouse_id:source.value,quantity:quantity.value,reason:reason.value,...(kind.value==='transfer'?{destination_id:destination.value}:{})}
 try{await api.post(`${base}/inventory/operations`,pending.value)}catch(e){if(axios.isAxiosError(e)&&e.response&&[403,404,409,422].includes(e.response.status))pending.value=null;throw e}
 pending.value=null;quantity.value='';reason.value='';notice.value='Inventory operation recorded.';await load()
}
async function refresh(){[products.value,warehouses.value]=await Promise.all([options('products'),options('warehouses')]);products.value=products.value.filter(p=>p.track_inventory);await load()}
onMounted(()=>run(refresh))
useTenantChanges(props.tenantId,['catalog','inventory'],refresh)
</script>
<template>
<section class="bg-white border rounded-xl p-6 space-y-5">
 <h2 class="text-xl font-semibold">Inventory</h2><p v-if="error" role="alert" class="text-red-800 bg-red-50 p-3">{{error}}</p><p v-if="notice" role="status" class="text-teal-800">{{notice}}</p>
 <div class="flex gap-4 flex-wrap"><label>View <select v-model="view" :disabled="busy" @change="page=1;run(load)" class="border rounded p-2"><option value="stocks">Stock balances</option><option value="movements">Movement history</option></select></label><label>Warehouse <select v-model="filter" :disabled="busy" @change="page=1;run(load)" class="border rounded p-2"><option value="">All warehouses</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{w.name}}</option></select></label><button :disabled="busy" @click="run(refresh)" class="underline">Refresh</button></div>
 <form v-if="canEdit" @submit.prevent="run(submit)" class="bg-stone-50 rounded p-4 space-y-3">
 <h3 class="font-semibold">Record stock change</h3><p class="text-sm text-stone-600">Use a positive adjustment for opening stock or receipts, and a negative adjustment for damage or corrections. Transfers move stock immediately.</p>
 <fieldset :disabled="busy||!!pending" class="grid sm:grid-cols-2 gap-3">
 <label>Operation<select v-model="kind" class="block w-full border rounded p-2"><option value="adjustment">Adjustment</option><option value="transfer">Transfer</option></select></label>
 <label>Product<select v-model="product" required class="block w-full border rounded p-2"><option value="" disabled>Select product</option><option v-for="p in products" :key="p.id" :value="p.id">{{p.name}}</option></select></label>
 <label>Source warehouse<select v-model="source" required class="block w-full border rounded p-2"><option value="" disabled>Select warehouse</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{w.name}}</option></select></label>
 <label v-if="kind==='transfer'">Destination<select v-model="destination" required class="block w-full border rounded p-2"><option value="" disabled>Select warehouse</option><option v-for="w in warehouses.filter(w=>w.id!==source)" :key="w.id" :value="w.id">{{w.name}}</option></select></label>
 <label>Quantity<input v-model="quantity" required inputmode="decimal" pattern="-?(0|[1-9][0-9]{0,8})(\.[0-9]{1,4})?" class="block w-full border rounded p-2" /></label>
 <label>Reason<input v-model="reason" required maxlength="500" class="block w-full border rounded p-2" /></label>
 </fieldset><p v-if="pending" class="text-sm">Result unknown. Retry this operation before making another change. Keep this page open.</p><button :disabled="busy" class="bg-teal-800 text-white px-4 py-2 rounded">{{pending?'Retry same operation':'Record change'}}</button>
 </form>
 <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th>Product</th><th>Warehouse</th><th>{{view==='stocks'?'On hand / reserved':'Change'}}</th><th>Details</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id" class="border-b"><td class="py-3">{{row.product_name}}</td><td>{{row.warehouse_name}}</td><td>{{view==='stocks'?`${row.on_hand} / ${row.reserved}`:row.quantity_delta}} {{row.unit}}</td><td>{{row.reason||''}} {{row.kind?`(${row.kind})`:''}}</td></tr></tbody></table></div>
 <p v-if="!rows.length&&!busy">No inventory records. Record opening stock to begin.</p><div class="flex justify-between"><button :disabled="busy||page<=1" @click="page--;run(load)">Previous</button><span>Page {{page}} of {{last}}</span><button :disabled="busy||page>=last" @click="page++;run(load)">Next</button></div>
</section>
</template>
