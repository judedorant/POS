<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { api } from './session'
import {getMeta,putMeta,sales,saveSale,lineAmount,type Preparation,type CachedProduct,type QueuedSale} from './offline-db'
const props=defineProps<{tenantId:string;userId:string;currency:string;role:string}>()
const partition=`${props.userId}:${props.tenantId}`,base=`/api/v1/tenants/${props.tenantId}`
const prepared=ref<Preparation>(),queue=ref<QueuedSale[]>([]),warehouses=ref<{id:string;name:string}[]>([]),warehouse=ref(''),device=ref('')
const busy=ref(false),error=ref(''),notice=ref(''),search=ref(''),cash=ref(''),cart=ref<{product:CachedProduct;quantity:string}[]>([]),connected=ref(navigator.onLine)
const reviews=ref<{id:string;operation_id:string;code:string;payload:string|object}[]>([]),reviewNote=ref('')
let timer:ReturnType<typeof setTimeout>|undefined,disposed=false,delay=15000,syncing=false
const matches=computed(()=>prepared.value?.products.filter(p=>!search.value||p.name.toLowerCase().includes(search.value.toLowerCase())||p.sku===search.value||p.barcode===search.value).slice(0,25)??[])
const estimate=computed(()=>{try{return cart.value.reduce((sum,l)=>sum+lineAmount(l.quantity,l.product.sale_price_minor),0)}catch{return null}})
const valid=computed(()=>!!prepared.value&&new Date(prepared.value.expires_at).getTime()>Date.now())
function stockEstimate(product:CachedProduct){
 if(!product.track_inventory)return 'Not tracked'
 function scaled(q:string){const negative=q.startsWith('-');const [a,b='']=q.replace('-','').split('.');const n=BigInt(a!)*10000n+BigInt(b.padEnd(4,'0'));return negative?-n:n}
 let balance=scaled(prepared.value?.stocks.find(s=>s.product_id===product.id)?.on_hand??'0')
 for(const sale of queue.value)if(prepared.value&&sale.command.occurred_at>=prepared.value.issued_at&&sale.status!=='rejected')for(const line of sale.command.items)if(line.product_id===product.id)balance-=scaled(line.quantity)
 const sign=balance<0n?'-':'';const abs=balance<0n?-balance:balance;return `${sign}${abs/10000n}.${String(abs%10000n).padStart(4,'0')}`
}
function reviewPayload(payload:string|object){return (typeof payload==='string'?JSON.parse(payload):payload) as {occurred_at:string;cash_tendered_minor:number;items:{product_id:string;quantity:string;expected_price_minor:number}[]}}
function exportReceipts(){const blob=new Blob([JSON.stringify(queue.value,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=`offline-receipts-${props.tenantId}.json`;a.click();URL.revokeObjectURL(url)}
function money(n:number){return new Intl.NumberFormat(undefined,{style:'currency',currency:props.currency}).format(n/100)}
async function run(fn:()=>Promise<void>){busy.value=true;error.value='';try{await fn()}catch(e){error.value=axios.isAxiosError(e)?e.response?.data?.message||'Connection unavailable. Local sales remain stored.':e instanceof Error?e.message:'Storage operation failed.'}finally{busy.value=false}}
async function loadQueue(){queue.value=(await sales(partition)).sort((a,b)=>b.command.occurred_at.localeCompare(a.command.occurred_at))}
async function locations(){warehouses.value=[];let page=1,last=1;do{const {data}=await api.get(`${base}/warehouses`,{params:{active:1,page}});warehouses.value.push(...data.data);last=data.last_page;page++}while(page<=last)}
async function prepare(){
 if(!warehouse.value)throw new Error('Select a warehouse first.')
 const {data}=await api.post(`${base}/offline/prepare`,{device_id:device.value,warehouse_id:warehouse.value})
 await putMeta(`preparation:${partition}`,data);prepared.value=data;cart.value=[]
 if(navigator.storage?.persist)await navigator.storage.persist().catch(()=>false)
 notice.value='Offline catalog saved. Authorization lasts eight hours.'
}
async function complete(){
 if(!prepared.value||new Date(prepared.value.expires_at).getTime()<=Date.now())throw new Error('Offline authorization expired. Connect to refresh it.')
 if(!cart.value.length||cart.value.length>100)throw new Error('Use between one and 100 products.')
 const lines=cart.value.map(l=>({name:l.product.name,quantity:l.quantity,amount:lineAmount(l.quantity,l.product.sale_price_minor)}));const total=lines.reduce((sum,l)=>sum+l.amount,0)
 if(total>999999999999||!/^\d+$/.test(cash.value)||!Number.isSafeInteger(Number(cash.value))||Number(cash.value)<total||Number(cash.value)>999999999999)throw new Error('Enter sufficient cash in minor units within the supported limit.')
 const id=crypto.randomUUID();const sale:QueuedSale={id,partition,status:'pending',total,lines,command:{operation_id:id,lease_id:prepared.value.lease_id,device_id:device.value,occurred_at:new Date().toISOString(),cash_tendered_minor:Number(cash.value),items:cart.value.map(l=>({product_id:l.product.id,quantity:l.quantity,expected_price_minor:l.product.sale_price_minor}))}}
 await saveSale(sale,true);cart.value=[];cash.value='';notice.value=`Completed locally — pending sync. Receipt ${id}`;await loadQueue();await synchronize()
}
async function synchronize(){
 if(syncing||!navigator.onLine)return
 syncing=true
 try{
  const pending=(await sales(partition)).filter(s=>['pending','retryable','requires_review'].includes(s.status)).slice(0,50)
  if(pending.length){const {data}=await api.post(`${base}/sync/orders`,{orders:pending.map(s=>s.command)})
   for(const result of data.results){const original=pending.find(s=>s.id===result.operation_id);if(original)await saveSale({...original,status:result.status,code:result.code,order_id:result.order_id})}
  }
  delay=15000;await loadQueue()
 }catch(e){delay=Math.min(delay*2,300000);if(axios.isAxiosError(e)&&[401,419].includes(e.response?.status??0))error.value='Sign in again to synchronize. Your local sales are retained.';else error.value='Sync unavailable. Local sales are retained and will retry.'}
 finally{syncing=false}
}
async function loadReviews(){reviews.value=(await api.get(`${base}/offline/reviews`)).data.data}
async function review(id:string,decision:string){if(!reviewNote.value.trim())throw new Error('Enter a review note.');await api.post(`${base}/offline/reviews/${id}`,{decision,note:reviewNote.value});reviewNote.value='';await loadReviews();await synchronize()}
function schedule(){if(disposed)return;timer=setTimeout(async()=>{await synchronize();schedule()},delay)}
function online(){connected.value=true;void synchronize()}
function offline(){connected.value=false}
onMounted(()=>run(async()=>{
 device.value=await getMeta<string>(`device:${partition}`)??crypto.randomUUID();await putMeta(`device:${partition}`,device.value)
 prepared.value=await getMeta<Preparation>(`preparation:${partition}`);warehouse.value=prepared.value?.warehouse_id??'';await loadQueue()
 window.addEventListener('online',online);window.addEventListener('offline',offline);schedule()
 if(navigator.onLine){await locations();await synchronize()}
}))
onUnmounted(()=>{disposed=true;if(timer)clearTimeout(timer);window.removeEventListener('online',online);window.removeEventListener('offline',offline)})
</script>
<template>
<section class="bg-white border rounded-xl p-6 space-y-5">
 <h2 class="text-xl font-semibold">Offline register <span class="text-sm font-normal">{{connected?'Connected':'Offline'}}</span></h2>
 <p class="text-sm text-stone-600">Prepare this device while connected. Completed local cash sales are saved before the cart clears. Stock shown elsewhere may be stale; synchronization can require reconciliation.</p>
 <p v-if="error" role="alert" class="bg-red-50 text-red-800 p-3">{{error}}</p><p v-if="notice" role="status" class="text-teal-800">{{notice}}</p>
 <div class="flex flex-wrap gap-3"><select v-model="warehouse" aria-label="Offline warehouse" :disabled="busy||!connected" class="border rounded p-2"><option value="">Select warehouse</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{w.name}}</option></select><button :disabled="busy||!connected" @click="run(prepare)" class="underline">Prepare / refresh offline catalog</button><button :disabled="busy||!connected" @click="run(locations)" class="underline">Refresh warehouses</button></div>
 <p v-if="prepared" class="text-sm">Authorized until {{new Date(prepared.expires_at).toLocaleString()}} · {{prepared.products.length}} cached products</p>
 <fieldset v-if="prepared" :disabled="busy||!valid" class="space-y-4">
 <input v-model="search" aria-label="Search cached products" placeholder="Search cached name, SKU or barcode" class="border rounded p-2 w-full" />
 <div class="grid sm:grid-cols-3 gap-2"><button v-for="p in matches" :key="p.id" @click="!cart.some(l=>l.product.id===p.id)&&cart.push({product:p,quantity:'1'})" class="border rounded p-3 text-left">{{p.name}} · {{money(p.sale_price_minor)}} / {{p.unit}}<span class="block text-xs text-stone-500">Stock estimate: {{stockEstimate(p)}}</span></button></div>
 <form @submit.prevent="run(complete)" class="space-y-3"><div v-for="(line,index) in cart" :key="line.product.id" class="flex gap-3 items-center"><span class="grow">{{line.product.name}}</span><input v-model="line.quantity" aria-label="Quantity" inputmode="decimal" class="border rounded p-2 w-24" /><button type="button" @click="cart.splice(index,1)" class="underline">Remove</button></div><p class="font-semibold">Total: {{estimate===null?'Check quantities':money(estimate)}}</p><label class="block">Cash received in minor units ({{currency}})<input v-model="cash" required inputmode="numeric" class="block border rounded p-2" /></label><button :disabled="!cart.length" class="bg-teal-800 text-white px-4 py-2 rounded">Complete locally</button></form>
 </fieldset>
 <div class="flex justify-between"><h3 class="font-semibold">Local receipts and synchronization</h3><button @click="exportReceipts" class="underline">Export receipts</button><button :disabled="busy||!connected" @click="run(synchronize)" class="underline">Sync now</button></div>
 <details v-for="sale in queue" :key="sale.id" class="border rounded p-3"><summary>{{money(sale.total)}} · {{sale.status.replaceAll('_',' ')}} <span v-if="sale.code==='negative_stock'">— negative stock needs reconciliation</span></summary><p class="text-xs break-all">{{sale.id}}</p><p v-for="line in sale.lines" :key="line.name">{{line.name}} × {{line.quantity}} · {{money(line.amount)}}</p><p>Cash {{money(sale.command.cash_tendered_minor)}} · Change {{money(sale.command.cash_tendered_minor-sale.total)}}</p><p>{{sale.code}}</p></details>
 <div v-if="role==='admin'&&connected" class="space-y-3 border-t pt-4"><button :disabled="busy" @click="run(loadReviews)" class="underline">Load sales awaiting review</button><label v-if="reviews.length" class="block">Review note<input v-model="reviewNote" class="block border rounded p-2 w-full" maxlength="1000" /></label><div v-for="item in reviews" :key="item.id" class="border rounded p-3"><p class="break-all">{{item.operation_id}} · {{item.code}}</p><details><summary>Submitted sale</summary><p>Recorded {{new Date(reviewPayload(item.payload).occurred_at).toLocaleString()}} · Cash {{money(reviewPayload(item.payload).cash_tendered_minor)}}</p><p v-for="line in reviewPayload(item.payload).items" :key="line.product_id">{{line.quantity}} × {{money(line.expected_price_minor)}} · Product {{line.product_id}}</p></details><div class="flex gap-4"><button :disabled="busy" @click="run(()=>review(item.id,'approve'))" class="underline">Approve and record sale</button><button :disabled="busy" @click="run(()=>review(item.id,'reject'))" class="underline">Reject</button></div></div></div>
</section>
</template>
