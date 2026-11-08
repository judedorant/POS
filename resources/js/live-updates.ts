import { onMounted, onUnmounted } from 'vue'
export type Topic='catalog'|'inventory'|'sales'
type Listener={topics:Topic[];refresh:()=>Promise<unknown>}
const listeners=new Map<string,Set<Listener>>()
export function useTenantChanges(tenant:string,topics:Topic[],refresh:()=>Promise<unknown>){
 const listener={topics,refresh}
 onMounted(()=>{if(!listeners.has(tenant))listeners.set(tenant,new Set());listeners.get(tenant)!.add(listener)})
 onUnmounted(()=>{listeners.get(tenant)?.delete(listener);if(!listeners.get(tenant)?.size)listeners.delete(tenant)})
}
export async function refreshTenant(tenant:string,topics:Topic[]){await Promise.all([...listeners.get(tenant)??[]].filter(l=>l.topics.some(t=>topics.includes(t))).map(l=>l.refresh()))}
