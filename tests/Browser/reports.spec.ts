import {test,expect} from '@playwright/test'
test('admin sees ledger-backed reports, low stock, and a filtered audit trail',async({page})=>{
 await page.goto('/');await page.getByLabel('Email',{exact:true}).fill('browser@example.com');await page.getByLabel('Password',{exact:true}).fill('browser-test-password');await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page.getByText('Hello, Browser Cashier')).toBeVisible()
 const name=await page.evaluate(async()=>{
  async function post(path:string,body:unknown){const cookie=document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='));const r=await fetch(path,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':decodeURIComponent(cookie?.split('=').slice(1).join('=')??'')},body:JSON.stringify(body)});if(!r.ok)throw new Error(await r.text());return r.json()}
  const name='reports-'+crypto.randomUUID();const tenant=await post('/api/v1/tenants',{name,slug:name,timezone:'UTC',default_currency:'USD'});const base=`/api/v1/tenants/${tenant.id}`
  const p=await post(base+'/products',{name:'Dashboard Coffee',sku:'COF',sale_price_minor:250,reorder_point:'3'});const w=await post(base+'/warehouses',{name:'Main','code':'MAIN'})
  await post(base+'/inventory/operations',{operation_id:crypto.randomUUID(),kind:'adjustment',product_id:p.id,warehouse_id:w.id,quantity:'2',reason:'Opening'})
  const receipt=await post(base+'/orders',{client_order_id:crypto.randomUUID(),warehouse_id:w.id,cash_tendered_minor:500,items:[{product_id:p.id,quantity:'1',expected_price_minor:250}]})
  await post(base+'/orders/'+receipt.order.id+'/refunds',{request_id:crypto.randomUUID(),reason:'Half returned',items:[{order_item_id:receipt.items[0].id,quantity:'0.5',restock:true}]})
  return name
 })
 await page.reload();await page.getByRole('button',{name:new RegExp(name)}).click()
 const reports=page.getByRole('region',{name:'Business reports'})
 await expect(reports.getByText('USD 2.50',{exact:true}).first()).toBeVisible()
 await expect(reports.getByText('USD 1.25',{exact:true}).first()).toBeVisible()
 await expect(reports.getByText('1 receipts checked · 0 ledger discrepancies',{exact:true})).toBeVisible()
 await expect(reports.getByText('Low stock · 1 product/location pairs',{exact:true})).toBeVisible()
 await expect(reports.getByRole('row').filter({hasText:'Dashboard Coffee'}).getByText('1.5000',{exact:true}).first()).toBeVisible()
 await reports.getByRole('button',{name:'Open audit trail',exact:true}).click()
 await reports.getByLabel('Action',{exact:true}).fill('sale.completed')
 await reports.getByRole('button',{name:'Filter audit',exact:true}).click()
 await expect(reports.getByText('1 matching entries',{exact:true})).toBeVisible()
 await expect(reports.locator('summary').filter({hasText:'sale.completed'})).toHaveCount(1)
})
