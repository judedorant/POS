import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'
import { getMeta, putMeta, removeMeta } from './offline-db'
export const api = axios.create({ timeout: 15000, withCredentials: true, withXSRFToken: true, headers: { Accept: 'application/json' } })
interface Tenant { id: string; name: string; slug: string; default_currency: string; timezone: string }
export const useSession = defineStore('session', () => {
  const user = ref<{ id: string; name: string } | null>(null)
  const tenants = ref<Tenant[]>([])
  const selected = ref<Tenant | null>(null)
  const role = ref('')
  async function restore() {
    try {
      user.value = (await api.get('/api/v1/me')).data; await loadTenants()
      const saved=await getMeta<{user:{id:string};tenant:Tenant}>('last-session')
      if(saved&&saved.user.id===user.value?.id&&tenants.value.some(t=>t.id===saved.tenant.id))await select(saved.tenant)
    }
    catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 401) { clear(); await removeMeta('last-session') }
      else if (axios.isAxiosError(error) && !error.response) {
        const saved = await getMeta<{user:{id:string;name:string};tenant:Tenant;role:string}>('last-session')
        if (!saved) throw error
        user.value=saved.user; selected.value=saved.tenant; tenants.value=[saved.tenant]; role.value=saved.role
      } else throw error
    }
  }
  async function loadTenants() { tenants.value = (await api.get('/api/v1/tenants')).data }
  async function login(email: string, password: string) {
    await api.get('/sanctum/csrf-cookie')
    user.value = (await api.post('/api/v1/login', { email, password })).data.user
    await loadTenants()
  }
  async function select(tenant: Tenant) {
    const { data } = await api.get(`/api/v1/tenants/${tenant.id}/context`)
    selected.value = data.tenant; role.value = data.role
    await putMeta('last-session',{user:user.value,tenant:data.tenant,role:data.role})
  }
  function clear() { user.value = null; tenants.value = []; selected.value = null; role.value = '' }
  async function logout() { await api.delete('/api/v1/session'); await removeMeta('last-session'); clear() }
  return { user, tenants, selected, role, restore, login, select, logout, loadTenants }
})
