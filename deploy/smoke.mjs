// Writes disposable fixtures. Refuses public hosts or missing explicit disposable guard.
import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
const origin = new URL(process.env.POS_SMOKE_URL ?? 'https://localhost:9443');
if (process.env.POS_SMOKE_DISPOSABLE !== '1' || !['localhost','127.0.0.1'].includes(origin.hostname)) throw new Error('Requires POS_SMOKE_DISPOSABLE=1 and a loopback deployment');
if (!process.env.POS_SMOKE_EMAIL || !process.env.POS_SMOKE_PASSWORD) throw new Error('Set disposable user credentials in POS_SMOKE_EMAIL/POS_SMOKE_PASSWORD');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true, args: ['--no-sandbox'] });
try {
 const context = await browser.newContext({ ignoreHTTPSErrors: true }); // Local Caddy test CA only.
 const page = await context.newPage();
 await page.routeWebSocket('wss://pos.example.com/**', socket => socket.close());
 await page.goto(origin.href);
 await page.getByLabel('Email',{exact:true}).fill(process.env.POS_SMOKE_EMAIL);
 await page.getByLabel('Password',{exact:true}).fill(process.env.POS_SMOKE_PASSWORD);
 await page.getByRole('button',{name:'Sign in',exact:true}).click();
 await page.getByText('Hello, Deployment Test').waitFor();
 const cookies=await context.cookies();
 assert(cookies.some(c=>c.httpOnly && c.secure),'Secure HttpOnly session missing');
 await page.reload(); await page.getByText('Hello, Deployment Test').waitFor();
 const result=await page.evaluate(async (publicKey)=>{
  async function post(path,body){
   const cookie=document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='));
   const response=await fetch(path,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':decodeURIComponent(cookie?.split('=').slice(1).join('=')??'')},body:JSON.stringify(body)});
   if(!response.ok)throw new Error(await response.text());return response.json();
  }
  const name='deployment-'+crypto.randomUUID();
  const tenant=await post('/api/v1/tenants',{name,slug:name,timezone:'UTC',default_currency:'USD'});
  const base=`/api/v1/tenants/${tenant.id}`;
  const product=await post(base+'/products',{name:'Deployment Coffee',sku:'COF',sale_price_minor:250,reorder_point:'3'});
  const warehouse=await post(base+'/warehouses',{name:'Main',code:'MAIN'});
  await post(base+'/inventory/operations',{operation_id:crypto.randomUUID(),kind:'adjustment',product_id:product.id,warehouse_id:warehouse.id,quantity:'10',reason:'Disposable opening stock'});
  const saleBody={client_order_id:crypto.randomUUID(),warehouse_id:warehouse.id,cash_tendered_minor:500,items:[{product_id:product.id,quantity:'1',expected_price_minor:250}]};
  const receipt=await post(base+'/orders',saleBody);
  const replay=await post(base+'/orders',saleBody);
  if(receipt.order.id!==replay.order.id)throw new Error('Sale retry duplicated order');
  const refundBody={request_id:crypto.randomUUID(),reason:'Disposable partial return',items:[{order_item_id:receipt.items[0].id,quantity:'0.5',restock:true}]};
  await post(base+'/orders/'+receipt.order.id+'/refunds',refundBody);
  await post(base+'/orders/'+receipt.order.id+'/refunds',refundBody);
  const socket=new WebSocket(`wss://${location.host}/app/${publicKey}?protocol=7&client=js&version=8.6.0&flash=false`);
  let changeResolve,changeReject,subscriptionResolve,connectionResolve;
  const connection=new Promise(resolve=>{connectionResolve=resolve});
  const subscribed=new Promise(resolve=>{subscriptionResolve=resolve});
  const changed=new Promise((resolve,reject)=>{changeResolve=resolve;changeReject=reject});
  const timeout=setTimeout(()=>changeReject(new Error('No realtime frame through Caddy')),30000);
  socket.onmessage=message=>{
   const frame=JSON.parse(message.data);const data=typeof frame.data==='string'?JSON.parse(frame.data):frame.data;
   if(frame.event==='pusher:connection_established')connectionResolve(data.socket_id);
   if(frame.event==='pusher_internal:subscription_succeeded')subscriptionResolve();
   if(frame.event==='tenant.changed')changeResolve(true);
  };
  const socketId=await Promise.race([connection,new Promise((_,reject)=>setTimeout(()=>reject(new Error('WebSocket handshake timeout')),10000))]);
  const channel=`private-tenants.${tenant.id}`;
  const auth=await post('/api/v1/broadcasting/auth',{socket_id:socketId,channel_name:channel});
  socket.send(JSON.stringify({event:'pusher:subscribe',data:{channel,auth:auth.auth}}));
  await Promise.race([subscribed,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Private subscription timeout')),10000))]);
  await post(base+'/inventory/operations',{operation_id:crypto.randomUUID(),kind:'adjustment',product_id:product.id,warehouse_id:warehouse.id,quantity:'1',reason:'Realtime smoke check'});
  await changed;clearTimeout(timeout);socket.close();
  const stocks=await(await fetch(base+'/inventory/stocks',{headers:{Accept:'application/json'}})).json();
  const orders=await(await fetch(base+'/orders',{headers:{Accept:'application/json'}})).json();
  return {stock:stocks.data[0].on_hand,orders:orders.total,tenant:tenant.id,realtime:true};
 }, process.env.POS_SMOKE_REVERB_KEY ?? 'phase9-public');
 assert.equal(result.stock,'10.5000');assert.equal(result.orders,1);assert.equal(result.realtime,true);
 console.log(JSON.stringify({status:'passed',...result}));
 await context.close();
} finally { await browser.close(); }
