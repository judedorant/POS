<script setup lang="ts">
import {ref} from 'vue'
import axios from 'axios'
import {api} from './session'
const props=defineProps<{tenantId:string;orderId:string;currency:string;allocations:{id:string;state:string;amount_minor:number}[];items:{id:string;name:string;quantity:string}[]}>()
const emit=defineEmits<{updated:[]}>()
const quantities=ref<Record<string,string>>({}),restock=ref<Record<string,boolean>>({}),reason=ref(''),retryNote=ref(''),busy=ref(false),error=ref(''),notice=ref('')
const key=`pos:refund:${props.tenantId}:${props.orderId}`
async function submit(){busy.value=true;error.value='';try{
 let payload
 const stored=sessionStorage.getItem(key)
 if(stored)payload=JSON.parse(stored)
 else{payload={request_id:crypto.randomUUID(),reason:reason.value,items:props.items.filter(i=>quantities.value[i.id]).map(i=>({order_item_id:i.id,quantity:quantities.value[i.id],restock:!!restock.value[i.id]}))};sessionStorage.setItem(key,JSON.stringify(payload))}
 const {data}=await api.post(`/api/v1/tenants/${props.tenantId}/orders/${props.orderId}/refunds`,payload)
 sessionStorage.removeItem(key);quantities.value={};reason.value='';const cash=data.allocations.filter((a:{state:string;method:string})=>a.state==='completed'&&a.method==='cash').reduce((sum:number,a:{amount_minor:number})=>sum+Number(a.amount_minor),0)
 notice.value=`Refund ${data.refund.state}. ${data.refund.replayed?'Previously recorded cash — verify it has not already been returned:':'Cash to return:'} ${new Intl.NumberFormat(undefined,{style:'currency',currency:props.currency}).format(cash/100)}. Card portions follow the original payment method.`;notice.value+=` Refund reference: ${data.refund.id}`;emit('updated')
 }catch(e){if(axios.isAxiosError(e)){if(e.response?.status===422)sessionStorage.removeItem(key);error.value=Object.values(e.response?.data?.errors??{}).flat().join(' ')||e.response?.data?.message||'Result unknown. Retry this request; do not return cash twice.'}else error.value='Storage failed. The refund was not submitted.'}finally{busy.value=false}}
async function retry(id:string){busy.value=true;error.value='';try{await api.post(`/api/v1/tenants/${props.tenantId}/payments/refund-allocations/${id}/retry`,{note:retryNote.value});emit('updated');notice.value='Failed card refund queued for a new audited attempt.'}catch(e){error.value=axios.isAxiosError(e)?e.response?.data?.message||'Retry failed.':'Retry failed.'}finally{busy.value=false}}
</script>
<template>
<div class="refund-panel border-t pt-4 space-y-3 print:hidden">
 <h4 class="font-semibold">Partial refund / return</h4><p class="text-sm text-stone-600">Enter only the quantities to refund. Check Restock for saleable returned goods. The server calculates the remaining refundable amount; cash is refunded first, then card.</p>
 <p v-if="error" role="alert" class="text-red-800">{{error}}</p><p v-if="notice" role="status" class="text-teal-800">{{notice}}</p>
 <div v-if="allocations.some(a=>a.state==='requires_review')" class="space-y-2"><label>Card refund review note<input v-model="retryNote" class="block border rounded p-2" /></label><button v-for="a in allocations.filter(a=>a.state==='requires_review')" :key="a.id" :disabled="busy||!retryNote.trim()" @click="retry(a.id)" class="underline">Verify failed card refund and retry</button></div>
 <form @submit.prevent="submit" class="space-y-3"><div v-for="item in items" :key="item.id" class="flex flex-wrap items-center gap-3"><label>{{item.name}} (sold {{item.quantity}})<input v-model="quantities[item.id]" inputmode="decimal" placeholder="Refund quantity" class="block border rounded p-2" /></label><label><input v-model="restock[item.id]" type="checkbox" /> Restock</label></div><label class="block">Refund reason<input v-model="reason" required maxlength="500" class="block border rounded p-2 w-full" /></label><button :disabled="busy" class="bg-stone-800 text-white px-4 py-2 rounded">{{busy?'Recording…':'Record refund'}}</button></form>
</div>
</template>
