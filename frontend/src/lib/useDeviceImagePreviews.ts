import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Device, DeviceImagePreview } from '../types'
import { getDeviceImagePreviews, onAuthIdentityChange } from './api'

export function useDeviceImagePreviews(device: () => Device) {
  const urls = ref<Record<string, string>>({})
  const loading = ref(false)
  const error = ref('')
  let entries: DeviceImagePreview[] = []
  let controller: AbortController | undefined
  let refreshTimer: ReturnType<typeof setTimeout> | undefined
  let expiryTimer: ReturnType<typeof setTimeout> | undefined
  let generation = 0
  let mounted = false
  let identityValid = true
  let unsubscribe: (() => void) | undefined

  function savedSources() { return new Set([...(device().pictures ?? []), ...(device().thumbs ?? [])].map(source => source.trim()).filter(Boolean)) }
  function clear() {
    generation++
    controller?.abort()
    clearTimeout(refreshTimer); clearTimeout(expiryTimer)
    entries = []
    urls.value = {}
    loading.value = false
    error.value = ''
  }
  function updateVisible() {
    clearTimeout(expiryTimer)
    entries = entries.filter(entry => entry.expiresAt * 1000 > Date.now())
    urls.value = Object.fromEntries(entries.map(entry => [entry.source, entry.url]))
    if (entries.length) {
      const expires = Math.min(...entries.map(entry => entry.expiresAt * 1000))
      expiryTimer = setTimeout(updateVisible, Math.max(1, expires - Date.now()))
    }
  }
  async function reload() {
    if (!mounted || !identityValid || loading.value || !savedSources().size) return
    const request = ++generation
    const sources = savedSources()
    clearTimeout(refreshTimer)
    controller = new AbortController()
    loading.value = true
    error.value = ''
    try {
      const result = await getDeviceImagePreviews(device().id, controller.signal)
      if (!mounted || !identityValid || request !== generation) return
      entries = result.filter(entry => sources.has(entry.source))
      updateVisible()
      if (entries.length) {
        const expires = Math.min(...entries.map(entry => entry.expiresAt * 1000))
        refreshTimer = setTimeout(() => { void reload() }, Math.max(5_000, expires - Date.now() - 30_000))
      } else if (result.some(entry => sources.has(entry.source))) {
        error.value = '图片预览已过期，请重试。'
      }
    } catch (failure) {
      if (!mounted || !identityValid || request !== generation) return
      const status = failure && typeof failure === 'object' && 'status' in failure ? failure.status : undefined
      if (status === 401 || status === 403 || status === 404) { entries = []; updateVisible() }
      error.value = '图片预览暂不可用，请重试。'
    } finally {
      if (mounted && request === generation) loading.value = false
    }
  }
  function restart() { clear(); if (mounted && identityValid) void reload() }
  watch(() => [device().id, JSON.stringify(device().pictures), JSON.stringify(device().thumbs)], restart, { flush: 'sync' })
  onMounted(() => {
    mounted = true
    unsubscribe = onAuthIdentityChange(() => { identityValid = false; clear() })
    restart()
  })
  onBeforeUnmount(() => { mounted = false; identityValid = false; clear(); unsubscribe?.() })
  return { urls, loading, error, reload }
}
