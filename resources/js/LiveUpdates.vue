<script setup lang="ts">
import { onMounted,onUnmounted,ref } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import axios from 'axios'
import {api} from './session'
import {refreshTenant,type Topic} from './live-updates'
const props=defineProps<{tenantId:string}>()
const status=ref('Connecting updates…')
let echo:Echo<'reverb'>|undefined,disposed=false,running=false,again=false,cursor='0',epoch:string|null=null,live=false
let timer:ReturnType<typeof setInterval>|undefined
async function recover(){
 if(disposed||!navigator.onLine){status.value='Offline — changes will refresh on reconnect';return}
 if(running){again=true;return}running=true
 try{
  let more=true
  while(more&&!disposed){
   const {data}=await api.get(`/api/v1/tenants/${props.tenantId}/changes`,{params:{after:cursor,epoch}})
   if(disposed)return
   const topics:Topic[]=data.reset?['catalog','inventory','sales']:[...new Set<Topic>(data.events.flatMap((e:{topics:Topic[]})=>e.topics))]
   if(topics.length)await refreshTenant(props.tenantId,topics)
   if(disposed)return
   cursor=data.cursor;epoch=data.epoch;more=data.has_more
  }
  status.value=live?'Live updates connected':'Updates checked periodically'
 }catch(e){
  status.value='Updates paused — retrying'
  if(axios.isAxiosError(e)&&[401,403,404,419].includes(e.response?.status??0)){echo?.disconnect();live=false;status.value='Updates unavailable — sign in or check access'}
 }finally{running=false;if(again&&!disposed){again=false;void recover()}}
}
function offline(){status.value='Offline — changes will refresh on reconnect'}
onMounted(()=>{
 const key=import.meta.env.VITE_REVERB_APP_KEY
 if(key){
  const tls=(import.meta.env.VITE_REVERB_SCHEME??'http')==='https'
  const client=new Pusher(key,{cluster:'mt1',wsHost:import.meta.env.VITE_REVERB_HOST??location.hostname,wsPort:Number(import.meta.env.VITE_REVERB_PORT??8080),wssPort:Number(import.meta.env.VITE_REVERB_PORT??443),forceTLS:tls,enabledTransports:['ws','wss'],disableStats:true,
   channelAuthorization:{endpoint:'/api/v1/broadcasting/auth',transport:'ajax',customHandler:(params,callback)=>{api.post('/api/v1/broadcasting/auth',{socket_id:params.socketId,channel_name:params.channelName}).then(r=>callback(null,r.data)).catch(e=>callback(e,null))}}
  })
  echo=new Echo<'reverb'>({broadcaster:'reverb',client})
  echo.private(`tenants.${props.tenantId}`).subscribed(()=>{live=true;void recover()}).listen('.tenant.changed',()=>{void recover()}).error(()=>{live=false;status.value='Updates checked periodically'})
  client.connection.bind('disconnected',()=>{live=false;status.value='Connection lost — checking changes periodically'})
 }
 void recover();timer=setInterval(()=>void recover(),30000);window.addEventListener('online',recover);window.addEventListener('offline',offline)
})
onUnmounted(()=>{disposed=true;if(timer)clearInterval(timer);echo?.disconnect();window.removeEventListener('online',recover);window.removeEventListener('offline',offline)})
</script>
<template><div class="flex items-center gap-3 text-sm text-stone-600" role="status"><span>{{status}}</span><button @click="recover" class="underline">Refresh updates</button></div></template>
