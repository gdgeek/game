<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ArrowRight, Eye, EyeOff, LockKeyhole, Monitor, ShieldCheck, UserRound, LoaderCircle, AlertCircle } from 'lucide-vue-next'
import ScanLogin from './ScanLogin.vue'
import type { BackendId, Session } from '../types'
const props = defineProps<{ busy: boolean; disabled?: boolean; error: string; demo: boolean; scanLoginEnabled?: boolean; backend: BackendId }>()
const emit = defineEmits<{ login: [username: string, password: string]; authenticated: [session: Session, backend: BackendId]; 'scan-busy': [busy: boolean] }>()
const username = ref('dirui')
const password = ref('')
const visible = ref(false)
const scan = ref<InstanceType<typeof ScanLogin>>()
const scanBusy = ref(false)
const canScan = computed(() => props.scanLoginEnabled === true && !props.demo)
const mode = ref<'scan' | 'password'>(canScan.value ? 'scan' : 'password')
watch([() => props.backend, canScan], () => { if (!scanBusy.value) mode.value = canScan.value ? 'scan' : 'password' })
const baseUrl = import.meta.env.BASE_URL
function submit() {
  if (!props.busy && !props.disabled && !scanBusy.value && username.value.trim() && password.value) emit('login', username.value.trim(), password.value)
}
function setScanBusy(busy: boolean) { scanBusy.value = busy; emit('scan-busy', busy) }
function stopScan() { if (!scanBusy.value) scan.value?.stop() }
function chooseMode(next: 'scan' | 'password') {
  if (props.busy || props.disabled || scanBusy.value || next === mode.value) return
  stopScan()
  mode.value = next
}
defineExpose({ stopScan })
</script>

<template>
  <main class="login-page">
    <section class="login-story" aria-label="不加班设备管理">
      <a class="brand login-brand" :href="baseUrl" aria-label="不加班首页"><span class="brand-symbol">不</span>不加班<span class="brand-caption">BUJIABAN</span></a>
      <div class="story-body">
        <span class="eyebrow light">设备管理</span>
        <h1>查看设备，<br />配置游戏场景<span class="green-period">.</span></h1>
        <p>选择设备，为它设置合适的游戏场景。</p>
        <div class="device-scene" aria-hidden="true">
          <div class="scene-ring ring-a"></div><div class="scene-ring ring-b"></div>
          <div class="scene-monitor monitor-back"><Monitor :size="70" :stroke-width="1" /></div>
          <div class="scene-monitor monitor-front"><div class="screen-dots"><i></i><i></i><i></i></div><Monitor :size="110" :stroke-width="0.85" /><span>DEVICE / SCENE</span><div class="screen-line"></div></div>
          <div class="connection-badge"><span class="status-dot online"></span>设备 · 游戏场景</div>
          <div class="scene-coordinates">BUJIABAN / DEVICE MANAGEMENT</div>
        </div>
      </div>
      <div class="story-footer"><span>不加班 · 设备管理后台</span><span>查看 / 配置 / 保存</span></div>
    </section>
    <section class="login-form-side">
      <span class="login-version">设备管理中心 <span>v1.0</span></span>
      <div class="login-form-wrap">
        <div class="login-lock"><LockKeyhole :size="24" :stroke-width="1.6" /></div>
        <h2>登录管理后台</h2><p class="login-description">查看设备列表与游戏场景配置。</p>
        <slot name="environment" />
        <div v-if="canScan" class="login-modes" aria-label="登录方式">
          <button type="button" :aria-pressed="mode === 'scan'" :disabled="busy || disabled || scanBusy" @click="chooseMode('scan')">微信扫码登录</button>
          <button type="button" :aria-pressed="mode === 'password'" :disabled="busy || disabled || scanBusy" @click="chooseMode('password')">账号密码登录</button>
        </div>
        <ScanLogin v-if="canScan && mode === 'scan' && !busy && !disabled" ref="scan" :key="backend" :backend="backend" @authenticated="(session, target) => emit('authenticated', session, target)" @busy="setScanBusy" />
        <p v-if="mode === 'scan' && error" class="form-error" role="alert"><AlertCircle :size="16" />{{ error }}</p>
        <form v-if="mode === 'password'" @submit.prevent="submit">
          <label for="username">用户名</label>
          <div class="input-with-icon"><UserRound :size="18" /><input id="username" v-model="username" name="username" autocomplete="username" required :disabled="busy || disabled" placeholder="输入用户名" /></div>
          <label for="password">密码</label>
          <div class="input-with-icon"><LockKeyhole :size="18" /><input id="password" v-model="password" :type="visible ? 'text' : 'password'" name="password" autocomplete="current-password" required :disabled="busy || disabled || scanBusy" placeholder="输入登录密码" /><button class="icon-button password-toggle" :disabled="busy || disabled || scanBusy" type="button" :aria-label="visible ? '隐藏密码' : '显示密码'" @click="visible = !visible"><EyeOff v-if="visible" :size="18" /><Eye v-else :size="18" /></button></div>
          <p v-if="error" class="form-error" role="alert"><AlertCircle :size="16" />{{ error }}</p>
          <button type="submit" class="button primary login-submit" :disabled="busy || disabled || !username.trim() || !password"><LoaderCircle v-if="busy" :size="18" class="spin" /><span>{{ busy ? '正在登录…' : '登录管理后台' }}</span><ArrowRight v-if="!busy" :size="18" /></button>
        </form>
        <p class="login-security"><ShieldCheck :size="15" />{{ mode === 'scan' ? '微信扫码仅可管理自己关联的设备' : '管理员账号可管理全部设备' }}</p>
        <div v-if="demo" class="login-demo"><span class="status-dot amber"></span>本地演示环境 · 使用已分配的账号登录</div>
      </div>
      <footer class="login-footer"><span>© {{ new Date().getFullYear() }} 不加班</span><span>设备管理</span></footer>
    </section>
  </main>
</template>

<style scoped>
.login-modes{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e0e6df;margin:0 0 22px;gap:18px}.login-modes button{background:none;border:0;border-bottom:2px solid transparent;padding:10px 0 13px;color:#89938b;font-size:12px;cursor:pointer}.login-modes button[aria-pressed=true]{color:#376946;border-bottom-color:#5a8566}.login-modes button:disabled{opacity:.6;cursor:wait}.login-modes button:focus-visible{outline:2px solid #679174;outline-offset:3px}
</style>
