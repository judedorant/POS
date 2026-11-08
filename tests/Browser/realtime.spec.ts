import {test,expect,type Page} from '@playwright/test'
async function login(page:Page){await page.goto('/');await page.getByLabel('Email',{exact:true}).fill('browser@example.com');await page.getByLabel('Password',{exact:true}).fill('browser-test-password');await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page.getByText('Hello, Browser Cashier')).toBeVisible()}
async function post(page:Page,path:string,body:unknown){return page.evaluate(async({path,body})=>{const cookie=document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='));const r=await fetch(path,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':decodeURIComponent(cookie?.split('=').slice(1).join('=')??'')},body:JSON.stringify(body)});if(!r.ok)throw new Error(await r.text());return r.json()},{path,body})}
test('two tills receive committed stock changes and recover missed updates',async({page,context,browser,baseURL})=>{
 const sequences=new Set<string>();page.on('websocket',socket=>socket.on('framereceived',frame=>{try{const message=JSON.parse(String(frame.payload));if(message.event==='tenant.changed')sequences.add(JSON.parse(message.data).sequence)}catch{}}))
 await login(page)
 const name='live-'+crypto.randomUUID();const tenant=await post(page,'/api/v1/tenants',{name,slug:name,timezone:'UTC',default_currency:'USD'});const base=`/api/v1/tenants/${tenant.id}`
 const product=await post(page,base+'/products',{name:'Live Rice',sku:'LIVE',sale_price_minor:100});const warehouse=await post(page,base+'/warehouses',{name:'Main','code':'MAIN'})
 await page.reload();await page.getByRole('button',{name:new RegExp(name)}).click()
 await expect(page.getByText('Live updates connected',{exact:true})).toBeVisible({timeout:15000})
 const otherContext=await browser.newContext({baseURL});const till=await otherContext.newPage()
 try{
  await login(till);await till.getByRole('button',{name:new RegExp(name)}).click()
  await post(till,base+'/inventory/operations',{operation_id:crypto.randomUUID(),kind:'adjustment',product_id:product.id,warehouse_id:warehouse.id,quantity:'5',reason:'Live test'})
  const panel=page.locator('section').filter({has:page.getByRole('heading',{name:'Inventory',exact:true})}).last()
  await expect(panel.getByText('5.0000 / 0.0000 each')).toBeVisible({timeout:20000})
  await expect.poll(()=>sequences.has('3'),{timeout:20000}).toBe(true)
  await context.setOffline(true)
  await post(till,base+'/inventory/operations',{operation_id:crypto.randomUUID(),kind:'adjustment',product_id:product.id,warehouse_id:warehouse.id,quantity:'2',reason:'While disconnected'})
  await context.setOffline(false)
  await expect(panel.getByText('7.0000 / 0.0000 each')).toBeVisible({timeout:15000})
 }finally{await otherContext.close()}
})
