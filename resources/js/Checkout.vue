<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import { api } from './session'
import RefundPanel from './RefundPanel.vue'
import { useTenantChanges } from './live-updates'
const props=defineProps<{tenantId:string;currency:string;userId:string;role:string}>()
interface Product {id:string;name:string;sku:string;sale_price_minor:number;unit:string}
interface Line {product:Product;quantity:string}
interface Receipt {refund_allocations:{id:string;state:string;amount_minor:number}[];order:{id:string;receipt_number:string;business_name:string;currency:string;total_minor:number;cash_tendered_minor:number;change_minor:number;completed_at:string;cash_applied_minor:number};refunds:{id:string;state:string;amount_minor:number}[];payments:{id:string;kind:string;method:string;amount_minor:number}[];items:{id:string;name:string;quantity:string;unit:string;unit_price_minor:number;line_total_minor:number}[]}
interface Command {mode?:'cash'|'stripe';cash_applied_minor?:number;client_order_id:string;warehouse_id:string;cash_tendered_minor:number;items:{product_id:string;quantity:string;expected_price_minor:number}[]}
const method=ref<'cash'|'stripe'>('cash'),cashApplied=ref('0'),stripeEnabled=ref(false)
interface PaymentSession{cash_received_minor:number;cash_applied_minor:number;id:string;state:string;total_minor:number;card_minor:number;currency:string;checkout_url:string|null;order_id:string|null}
const sessions=ref<PaymentSession[]>([])
const key=`pos:pending-sale:${props.userId}:${props.tenantId}`
const pending=ref<Command|null>(null),products=ref<Product[]>([]),cart=ref<Line[]>([]),warehouses=ref<{id:string;name:string}[]>([])
const warehouse=ref(''),search=ref(''),cash=ref(''),busy=ref(false),error=ref(''),receipt=ref<Receipt|null>(null),history=ref<Receipt['order'][]>([])
const base=`/api/v1/tenants/${props.tenantId}`
function money(value:number,currency=props.currency){return new Intl.NumberFormat(undefined,{style:'currency',currency}).format(value/100)}
function amount(line:Line):number {
 if(!/^(0|[1-9][0-9]{0,5})(\.[0-9]{1,4})?$/.test(line.quantity))return 0
 const [whole,part='']=line.quantity.split('.');const scaled=BigInt(whole!)*10000n+BigInt(part.padEnd(4,'0'))
 return Number((scaled*BigInt(line.product.sale_price_minor)+5000n)/10000n)
}
const total=computed(()=>cart.value.reduce((sum,line)=>sum+amount(line),0))
async function run(fn:()=>Promise<void>){busy.value=true;error.value='';try{await fn()}catch(e){error.value=axios.isAxiosError(e)?Object.values(e.response?.data?.errors??{}).flat().join(' ')||e.response?.data?.message||'Connection lost. Retry the pending sale.':'Unable to save this sale. Check browser storage and retry.'}finally{busy.value=false}}
async function catalog(){const {data}=await api.get(`${base}/products`,{params:{q:search.value,active:1}});products.value=data.data}
async function receipts(){history.value=(await api.get(`${base}/orders`)).data.data}
function add(p:Product){if(cart.value.some(l=>l.product.id===p.id))return;cart.value.push({product:p,quantity:'1'});receipt.value=null}
async function checkout(){
 if(!pending.value){
  const command:Command={mode:method.value,...(method.value==='stripe'?{cash_applied_minor:Number(cashApplied.value)}:{}),client_order_id:crypto.randomUUID(),warehouse_id:warehouse.value,cash_tendered_minor:Number(cash.value),items:cart.value.map(l=>({product_id:l.product.id,quantity:l.quantity,expected_price_minor:l.product.sale_price_minor}))}
  sessionStorage.setItem(key,JSON.stringify(command));pending.value=command
 }
 let data:Receipt
 try {
  if(pending.value.mode==='stripe'){
   const result=(await api.post(`${base}/payments`,pending.value)).data
   sessionStorage.removeItem(key);pending.value=null
   if(result.order_id){await openReceipt(result.order_id);cart.value=[];await loadPayments();return}
   if(result.checkout_url){window.location.assign(result.checkout_url);return}
   throw new Error('Payment is awaiting reconciliation.')
  }
  data=(await api.post(`${base}/orders`,pending.value)).data
 }
 catch(e){if(axios.isAxiosError(e)&&e.response&&e.response.status===422){sessionStorage.removeItem(key);pending.value=null}throw e}
 receipt.value=data;sessionStorage.removeItem(key);pending.value=null;cart.value=[];cash.value='';await receipts()
}
async function reviewPrices(){await catalog();for(const line of cart.value){const {data}=await api.get(`${base}/products/${line.product.id}`);line.product=data}}
async function loadPayments(){const {data}=await api.get(`${base}/payments`);stripeEnabled.value=data.stripe_enabled;sessions.value=data.sessions}
async function reconcile(id:string,action='reconcile'){const {data}=await api.post(`${base}/payments/${id}/${action}`);await loadPayments();await receipts();if(data.order_id)await openReceipt(data.order_id)}
async function openReceipt(id:string){receipt.value=(await api.get(`${base}/orders/${id}`)).data}
function print(){window.print()}
onMounted(()=>run(async()=>{
 const saved=sessionStorage.getItem(key);if(saved)pending.value=JSON.parse(saved)
 let page=1,last=1;do{const {data}=await api.get(`${base}/warehouses`,{params:{active:1,page}});warehouses.value.push(...data.data);last=data.last_page;page++}while(page<=last)
 await Promise.all([catalog(),receipts(),loadPayments()])
}))
useTenantChanges(props.tenantId,['catalog','sales'],async()=>{await Promise.all([catalog(),receipts(),loadPayments()])})
</script>
<template>
<section class="bg-white border rounded-xl p-6 space-y-5 checkout">
 <div class="checkout-controls space-y-5">
 <h2 class="text-xl font-semibold">Checkout</h2><p class="text-sm text-stone-600">Cash or Stripe test card, including split cash/card. No discounts or tax applied.</p>
 <p v-if="error" role="alert" class="bg-red-50 text-red-800 p-3">{{error}}</p>
 <div v-if="pending" class="bg-amber-50 p-4 space-y-2"><p>A sale is awaiting confirmation. Retry with the same order ID to recover its receipt safely.</p><p class="text-xs break-all">{{pending.client_order_id}}</p><button :disabled="busy" @click="run(checkout)" class="underline">Retry pending sale</button></div>
 <fieldset :disabled="busy||!!pending" class="space-y-4">
 <label>Warehouse <select v-model="warehouse" class="border rounded p-2"><option value="">Select warehouse</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{w.name}}</option></select></label>
 <form @submit.prevent="run(catalog)" class="flex gap-2"><input v-model="search" aria-label="Search products" placeholder="Name, exact SKU or barcode" class="border rounded p-2 grow" /><button class="border rounded px-3">Search</button></form>
 <div class="grid sm:grid-cols-3 gap-2"><button v-for="p in products" :key="p.id" @click="add(p)" class="border rounded p-3 text-left"><span class="block font-semibold">{{p.name}}</span><span class="text-sm">{{p.sku}} · {{money(p.sale_price_minor)}} / {{p.unit}}</span></button></div>
 <p v-if="!products.length">No matching products.</p>
 <form @submit.prevent="run(checkout)" class="space-y-4">
 <div v-for="(line,index) in cart" :key="line.product.id" class="flex flex-wrap gap-3 items-center border-b py-2"><span class="grow">{{line.product.name}}</span><label>Quantity <input v-model="line.quantity" required pattern="(0|[1-9][0-9]{0,5})(\.[0-9]{1,4})?" inputmode="decimal" class="border rounded p-2 w-28" /></label><span>{{money(amount(line))}}</span><button type="button" @click="cart.splice(index,1)" class="underline">Remove</button></div>
 <p class="text-lg font-semibold">Estimated total: {{money(total)}}</p><button type="button" @click="run(reviewPrices)" class="underline text-sm">Refresh cart prices</button>
 <label class="block">Payment method<select v-model="method" class="block border rounded p-2"><option value="cash">Cash</option><option value="stripe" :disabled="!stripeEnabled">Stripe test card / split tender</option></select></label><label v-if="method==='stripe'" class="block">Cash portion applied in minor units<input v-model="cashApplied" required type="number" min="0" :max="total-1" step="1" class="block border rounded p-2" /></label><label class="block">Cash received in minor units ({{currency}})<input v-model="cash" required type="number" min="0" max="999999999999" step="1" class="block border rounded p-2" /></label>
 <button :disabled="!cart.length||!warehouse||busy" class="bg-teal-800 text-white rounded px-5 py-3 disabled:opacity-50">{{method==='stripe'?'Reserve stock and open Stripe test checkout':'Complete cash sale'}}</button>
 </form>
 </fieldset>
 <details><summary class="cursor-pointer">Card payment attempts</summary><div v-for="attempt in sessions" :key="attempt.id" class="border rounded p-3 my-2 space-y-2"><p>{{money(attempt.total_minor,attempt.currency)}} · {{attempt.state}}</p><p v-if="attempt.state==='reserved'" class="text-sm">Cash received: {{money(attempt.cash_received_minor,attempt.currency)}}. Return this cash if the payment is canceled; settlement is recorded only after card confirmation.</p><div class="flex gap-3"><a v-if="attempt.checkout_url&&attempt.state==='reserved'" :href="attempt.checkout_url" class="underline">Resume Stripe test checkout</a><button :disabled="busy" @click="run(()=>reconcile(attempt.id))" class="underline">Check provider status</button><button v-if="attempt.state==='reserved'" :disabled="busy" @click="run(()=>reconcile(attempt.id,'cancel'))" class="underline">Cancel unpaid payment</button></div></div></details>
 <details><summary class="cursor-pointer">Recent receipts</summary><ul class="space-y-2 mt-3"><li v-for="order in history" :key="order.id"><button :disabled="busy" @click="run(()=>openReceipt(order.id))" class="underline text-sm break-all">{{order.receipt_number}} · {{money(order.total_minor,order.currency)}}</button></li></ul></details>
 </div>
 <article v-if="receipt" class="receipt border border-dashed p-6 space-y-3">
 <h3 class="text-xl font-semibold">{{receipt.order.business_name}}</h3><p class="text-xs break-all">{{receipt.order.receipt_number}}</p><p>{{new Date(receipt.order.completed_at).toLocaleString()}}</p>
 <div v-for="item in receipt.items" :key="item.id" class="flex justify-between gap-4"><span>{{item.name}} · {{item.quantity}} {{item.unit}}</span><span>{{money(item.line_total_minor,receipt.order.currency)}}</span></div>
 <p class="font-bold">Total: {{money(receipt.order.total_minor,receipt.order.currency)}}</p><p>Cash: {{money(receipt.order.cash_tendered_minor,receipt.order.currency)}}</p><p>Change: {{money(receipt.order.change_minor,receipt.order.currency)}}</p><p v-for="payment in receipt.payments" :key="payment.id">{{payment.kind}} · {{payment.method}} · {{money(payment.amount_minor,receipt.order.currency)}}</p><p v-for="refund in receipt.refunds" :key="refund.id">Refund {{refund.state}} · {{money(refund.amount_minor,receipt.order.currency)}}</p><button @click="print" class="print-button underline">Print receipt</button>
  <RefundPanel v-if="role==='admin'" :key="receipt.order.id" :tenant-id="tenantId" :order-id="receipt.order.id" :currency="receipt.order.currency" :items="receipt.items" :allocations="receipt.refund_allocations" @updated="run(()=>openReceipt(receipt!.order.id))" />
 </article>
</section>
</template>
