import {test,expect} from '@playwright/test'
test('admin records a partial cash refund and restocks once',async({page})=>{
 await page.goto('/');await page.getByLabel('Email',{exact:true}).fill('browser@example.com');await page.getByLabel('Password',{exact:true}).fill('browser-test-password');await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page.getByText('Hello, Browser Cashier')).toBeVisible()
 const ids=await page.evaluate(async()=>{
  async function post(path:string,body:unknown){const cookie=document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='));const r=await fetch(path,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':decodeURIComponent(cookie?.split('=').slice(1).join('=')??'')},body:JSON.stringify(body)});if(!r.ok)throw new Error(await r.text());return r.json()}
  const name='refund-'+crypto.randomUUID();const tenant=await post('/api/v1/tenants',{name,slug:name,timezone:'UTC',default_currency:'USD'});const base=`/api/v1/tenants/${tenant.id}`;const p=await post(base+'/products',{name:'Return Coffee',sku:'COF',sale_price_minor:250});const w=await post(base+'/warehouses',{name:'Main','code':'MAIN'});await post(base+'/inventory/operations',{operation_id:crypto.randomUUID(),kind:'adjustment',product_id:p.id,warehouse_id:w.id,quantity:'10',reason:'Opening'});return {name,tenant:tenant.id,warehouse:w.id}
 })
 await page.reload();await page.getByRole('button',{name:new RegExp(ids.name)}).click()
 const checkout=page.getByRole('heading',{name:'Checkout',exact:true}).locator('../..');await checkout.getByRole('combobox',{name:'Warehouse',exact:true}).selectOption(ids.warehouse)
 await checkout.getByRole('button',{name:/Return Coffee/}).click();await checkout.getByLabel('Quantity',{exact:true}).fill('2');await checkout.getByLabel('Cash received in minor units').fill('1000');await checkout.getByRole('button',{name:'Complete cash sale',exact:true}).click()
 await expect(checkout.locator('.receipt')).toBeVisible()
 await checkout.getByPlaceholder('Refund quantity').fill('1');await checkout.getByLabel('Restock',{exact:true}).check();await checkout.getByLabel('Refund reason').fill('Customer return');await checkout.getByRole('button',{name:'Record refund',exact:true}).click()
 await expect(checkout.getByText(/Refund completed\. Cash to return:/)).toBeVisible()
 const result=await page.evaluate(async tenant=>{const base=`/api/v1/tenants/${tenant}`;const stocks=await(await fetch(base+'/inventory/stocks',{headers:{Accept:'application/json'}})).json();const refunds=await(await fetch(base+'/refunds',{headers:{Accept:'application/json'}})).json();return {stock:stocks.data[0].on_hand,refunds:refunds.total}},ids.tenant)
 expect(result).toEqual({stock:'9.0000',refunds:1})
})
