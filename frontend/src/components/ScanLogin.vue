<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { AlertCircle, LoaderCircle, QrCode, RefreshCw } from 'lucide-vue-next'
import QRCode from 'qrcode'
import type { BackendId, Session } from '../types'
import { cancelScanLoginTicket, consumeScanLoginTicket, createScanLoginTicket, getScanLoginTicket, getSession } from '../lib/api'
import { createScanLoginController, type ScanLoginState } from '../lib/scan-login'

const props = defineProps<{ backend: BackendId }>()
const emit = defineEmits<{ authenticated: [session: Session, backend: BackendId]; busy: [value: boolean] }>()
// Keep every request, including late cleanup and Session recovery, on this backend.
const backend = props.backend
const state = ref<ScanLoginState>({ phase: 'idle', ticket: null, error: '' })
const qrImage = ref('')
const qrError = ref('')
let imageVersion = 0
const controller = createScanLoginController({
  api: {
    create: () => createScanLoginTicket(backend),
    get: id => getScanLoginTicket(id, backend),
    consume: id => consumeScanLoginTicket(id, backend),
    cancel: id => cancelScanLoginTicket(id, backend),
    session: () => getSession(backend),
  },
  onState: value => { state.value = value },
  onSession: session => emit('authenticated', session, backend),
  onConsumeBusy: busy => emit('busy', busy),
})
const changingSession = computed(() => ['consuming', 'reconciling'].includes(state.value.phase))
const creating = computed(() => ['idle', 'creating'].includes(state.value.phase))
const message = computed(() => ({
  idle: '正在准备二维码…', creating: '正在生成二维码…', pending: '等待小程序扫码确认',
  consuming: '已确认，正在安全登录…', reconciling: '正在确认登录状态…',
  expired: '二维码已过期，请刷新', cancelled: '二维码已取消，请刷新',
  consumed: '二维码已使用', error: '扫码登录暂时不可用',
})[state.value.phase])
watch(() => state.value.ticket?.qrText, async text => {
  const version = ++imageVersion
  qrImage.value = ''
  qrError.value = ''
  if (!text) return
  try {
    const result = await QRCode.toDataURL(text, { width: 240, margin: 3, errorCorrectionLevel: 'M', color: { dark: '#14241b', light: '#ffffff' } })
    if (version === imageVersion) qrImage.value = result
  } catch {
    if (version === imageVersion) qrError.value = '无法绘制二维码，请刷新后重试。'
  }
})
function stop() { imageVersion++; controller.dispose() }
defineExpose({ stop })
onMounted(() => { void controller.refresh() })
onBeforeUnmount(stop)
</script>

<template>
  <section class="scan-login" aria-label="微信小程序扫码登录" :aria-busy="changingSession || creating">
    <div class="scan-code-frame">
      <img v-if="qrImage && state.phase === 'pending' && !qrError" :src="qrImage" width="240" height="240" alt="微信小程序扫码登录二维码" />
      <div v-else class="scan-placeholder" role="status">
        <LoaderCircle v-if="creating || changingSession" :size="30" class="spin" />
        <QrCode v-else :size="40" :stroke-width="1.4" />
        <span>{{ message }}</span>
      </div>
    </div>
    <p class="scan-status" role="status">{{ message }}</p>
    <p class="scan-instructions">打开「不加班」微信小程序<br />进入 <strong>我的 → 扫码登录设备后台</strong><br />扫描二维码后，在小程序内确认登录。</p>
    <p class="scan-expiry">登录后仅可管理当前微信账号关联的设备。</p>
    <p v-if="state.phase === 'pending'" class="scan-expiry">二维码有效期 5 分钟，请使用自己的微信账号确认。</p>
    <p v-if="state.error || qrError" class="form-error" role="alert"><AlertCircle :size="16" />{{ state.error || qrError }}</p>
    <button v-if="state.phase === 'reconciling' && state.error" type="button" class="button secondary scan-refresh" @click="controller.retrySession"><RefreshCw :size="15" />重试确认登录状态</button>
    <button v-else type="button" class="button secondary scan-refresh" :disabled="creating || changingSession" @click="controller.refresh"><RefreshCw :size="15" />刷新二维码</button>
  </section>
</template>

<style scoped>
.scan-login{text-align:center}.scan-code-frame{width:242px;height:242px;max-width:100%;margin:0 auto;border:1px solid #e3e9e1;border-radius:12px;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center}.scan-code-frame img{width:240px;height:240px;max-width:100%;object-fit:contain}.scan-placeholder{display:flex;flex-direction:column;align-items:center;gap:17px;color:#69816d;font-size:12px;padding:20px;line-height:1.7}.scan-status{font-size:12px;color:#4c6653;margin:15px 0 12px}.scan-instructions{font-size:12px;line-height:1.9;color:#77857a}.scan-instructions strong{font-weight:500;color:#405d47}.scan-expiry{font-size:10px;line-height:1.7;color:#8b968e;margin-top:9px}.scan-refresh{margin:17px auto 0;font-size:11px;min-height:34px;justify-content:center}.scan-login .form-error{text-align:left;justify-content:center}.scan-code-frame:has(img){background:white}@media(max-width:380px){.scan-code-frame{width:220px;height:220px}.scan-code-frame img{width:218px;height:218px}}
</style>
