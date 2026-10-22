<script setup lang="ts">
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { ArrowDownToLine, ArrowRight, Check, ChevronDown, ChevronLeft, ChevronRight, CircleHelp, CircleX, Gamepad2, LayoutGrid, LoaderCircle, LogOut, Menu, Monitor, MonitorCheck, RefreshCw, Search, Settings2, ShieldCheck, SlidersHorizontal, Sparkles, Unplug, X } from 'lucide-vue-next'
import LoginView from './components/LoginView.vue'
import GamePicker from './components/GamePicker.vue'
import GameArtwork from './components/GameArtwork.vue'
import HelpDialog from './components/HelpDialog.vue'
import type { Device, DeviceConfigurationPatch, Game, Session } from './types'
import { ApiError, updateDeviceConfiguration, getDevices, getGames, getSession, initializeApi, isDemoMode, login, logout, onAuthIdentityChange } from './lib/api'
import { filterDevices, summarizeDevices } from './lib/devices'

const ready = ref(false)
const user = ref<Session['user']>(null)
const demo = ref(isDemoMode)
const configurationReady = ref(isDemoMode)
const configurationBusy = ref(false)
const configurationError = ref('')
const connectingSession = ref(false)
const loginBusy = ref(false)
const scanConsumeBusy = ref(false)
const scanLoginEnabled = ref(false)
const loginView = ref<InstanceType<typeof LoginView>>()
const loginError = ref('')
const devices = ref<Device[]>([])
const games = ref<Game[]>([])
const loading = ref(false)
const devicesError = ref('')
const gamesError = ref('')
const lastSynced = ref<Date | null>(null)
const activeView = ref<'devices' | 'games'>('devices')
const search = ref('')
const status = ref<'all' | 'online' | 'offline' | 'unknown' | 'configured' | 'unconfigured'>('all')
const gameFilter = ref<number | null>(null)
const gameSearch = ref('')
const page = ref(1)
const pageSize = ref(8)
const sidebarOpen = ref(false)
const sidebar = ref<HTMLElement>()
const pickerDevice = ref<Device | null>(null)
const saving = ref(false)
const saveError = ref('')
const toast = ref<{ text: string; error: boolean } | null>(null)
const helpOpen = ref(false)
const logoutBusy = ref(false)
let toastTimeout: ReturnType<typeof setTimeout> | undefined
let loadVersion = 0
let viewVersion = 0
const baseUrl = import.meta.env.BASE_URL

const summary = computed(() => summarizeDevices(devices.value))
const usedGameCount = computed(() => new Set(devices.value.map(device => device.gameId).filter(id => id !== null)).size)
const filteredDevices = computed(() => filterDevices(devices.value, { search: search.value, status: status.value, gameId: gameFilter.value }))
const pageCount = computed(() => Math.max(1, Math.ceil(filteredDevices.value.length / pageSize.value)))
const pageDevices = computed(() => filteredDevices.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))
const filteredGames = computed(() => games.value.filter(game => `${game.name} ${game.id} ${game.category}`.toLowerCase().includes(gameSearch.value.toLowerCase())))
const syncingTime = computed(() => lastSynced.value?.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }) || '尚未同步')
const statusLabels = { online: '在线', offline: '离线', unknown: '状态未知' }
const currentTitle = computed(() => activeView.value === 'devices' ? '设备管理' : '游戏库')
const deviceManager = computed(() => user.value?.role === 'device_manager')
const identityLabel = computed(() => deviceManager.value ? '关联设备管理' : '管理员')

watch([search, status, gameFilter, pageSize], () => { page.value = 1 })
watch(pageCount, count => { if (page.value > count) page.value = count })
watch(activeView, () => { sidebarOpen.value = false })
watch(sidebarOpen, async open => {
  await nextTick()
  if (open) sidebar.value?.querySelector<HTMLButtonElement>('.sidebar-close')?.focus()
  else document.querySelector<HTMLButtonElement>('.mobile-menu')?.focus()
})
function sidebarKeydown(event: KeyboardEvent) {
  if (!sidebarOpen.value) return
  if (event.key === 'Escape') { sidebarOpen.value = false; return }
  if (event.key !== 'Tab') return
  const controls = [...(sidebar.value?.querySelectorAll<HTMLElement>('a[href],button:not(:disabled)') || [])]
  const first = controls[0], last = controls.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}

function showToast(text: string, error = false) {
  clearTimeout(toastTimeout)
  toast.value = { text, error }
  toastTimeout = setTimeout(() => { toast.value = null }, 4500)
}
function messageOf(error: unknown, fallback: string) { return error instanceof Error ? error.message : fallback }
function resetWorkspace() {
  user.value = null
  devices.value = []
  games.value = []
  pickerDevice.value = null
  helpOpen.value = false
  sidebarOpen.value = false
  loading.value = false
  connectingSession.value = false
  saving.value = false
  loginBusy.value = false
  logoutBusy.value = false
  scanLoginEnabled.value = false
  lastSynced.value = null
  activeView.value = 'devices'
  search.value = ''
  status.value = 'all'
  gameFilter.value = null
  gameSearch.value = ''
  page.value = 1
  loginError.value = ''
  saveError.value = ''
  devicesError.value = ''
  gamesError.value = ''
  toast.value = null
  clearTimeout(toastTimeout)
}
function expireSession(error: unknown) {
  if (error instanceof ApiError && error.status === 401) {
    viewVersion++
    loadVersion++
    resetWorkspace()
    connectingSession.value = false
    ready.value = true
    loginError.value = '登录已过期，请重新登录。'
    return true
  }
  return false
}
async function loadData() {
  if (!user.value) return
  const currentView = viewVersion
  const version = ++loadVersion
  loading.value = true
  devicesError.value = ''
  gamesError.value = ''
  const results = await Promise.allSettled([getDevices(), getGames()])
  if (version !== loadVersion || currentView !== viewVersion) return
  const [deviceResult, gameResult] = results
  if (deviceResult.status === 'fulfilled') { devices.value = deviceResult.value; lastSynced.value = new Date() }
  else if (!expireSession(deviceResult.reason)) devicesError.value = messageOf(deviceResult.reason, '设备加载失败，请重试。')
  if (version !== loadVersion || currentView !== viewVersion) return
  if (gameResult.status === 'fulfilled') games.value = gameResult.value
  else if (!expireSession(gameResult.reason)) gamesError.value = messageOf(gameResult.reason, '游戏目录加载失败，请重试。')
  loading.value = false
}
async function submitLogin(username: string, password: string) {
  if (!configurationReady.value || loginBusy.value || scanConsumeBusy.value || connectingSession.value || configurationBusy.value) return
  loginView.value?.stopScan()
  const version = viewVersion
  loginBusy.value = true
  loginError.value = ''
  try {
    const session = await login(username, password)
    if (version !== viewVersion) return
    user.value = session.user
    demo.value = isDemoMode && session.demo === true
    if (session.user) await loadData()
    else loginError.value = '登录未成功，请重试。'
  } catch (error) { if (version === viewVersion) loginError.value = messageOf(error, '登录失败，请检查网络后重试。') }
  finally { if (version === viewVersion) loginBusy.value = false }
}
async function acceptScanSession(session: Session) {
  if (!scanConsumeBusy.value || loginBusy.value || connectingSession.value || !session.user) return
  const version = viewVersion
  user.value = { ...session.user, role: session.user.role ?? 'device_manager' }
  demo.value = false
  loginError.value = ''
  if (version === viewVersion) await loadData()
}
async function signOut() {
  if (logoutBusy.value || scanConsumeBusy.value || saving.value) return
  const version = viewVersion
  logoutBusy.value = true
  try {
    await logout()
    if (version !== viewVersion) return
    viewVersion++
    loadVersion++
    resetWorkspace()
  } catch (error) { if (version === viewVersion && !expireSession(error)) showToast(messageOf(error, '退出失败，请重试。'), true) }
  finally { if (version === viewVersion) logoutBusy.value = false }
}
function openPicker(device: Device) {
  if (loading.value || devicesError.value) return
  saveError.value = ''
  pickerDevice.value = device
}
async function saveConfiguration(configuration: DeviceConfigurationPatch) {
  if (!user.value || !pickerDevice.value || saving.value || connectingSession.value) return
  const version = viewVersion
  const target = pickerDevice.value
  saving.value = true
  saveError.value = ''
  try {
    const updated = await updateDeviceConfiguration(target.id, configuration)
    if (version !== viewVersion) return
    devices.value = devices.value.map(device => device.id === updated.id ? updated : device)
    pickerDevice.value = null
    showToast(`已保存「${updated.name || `设备 #${target.id}`}」的设备配置`)
  } catch (error) { if (version === viewVersion && !expireSession(error)) saveError.value = messageOf(error, '配置保存失败，请重试。') }
  finally { if (version === viewVersion) saving.value = false }
}

async function connectSession() {
  const version = viewVersion
  connectingSession.value = true
  loginError.value = ''
  try {
    const session = await getSession()
    if (version !== viewVersion) return
    user.value = session.user
    demo.value = isDemoMode && session.demo === true
    scanLoginEnabled.value = !isDemoMode && session.scanLoginEnabled === true
    if (user.value) await loadData()
  } catch (error) {
    if (version === viewVersion) loginError.value = messageOf(error, '无法连接服务，请稍后重试。')
  } finally {
    if (version === viewVersion) { connectingSession.value = false; ready.value = true }
  }
}

async function initializeWorkspace() {
  if (configurationBusy.value) return
  configurationBusy.value = true
  loginError.value = ''
  try {
    await initializeApi()
    configurationReady.value = true
    configurationError.value = ''
    await connectSession()
  } catch (error) {
    configurationReady.value = false
    configurationError.value = messageOf(error, '无法加载后端配置，请重试加载配置。')
    ready.value = true
  } finally { configurationBusy.value = false }
}

function gameById(id: number | null) { return games.value.find(game => game.id === id) }
function gameDeviceCount(id: number) { return devices.value.filter(device => device.gameId === id).length }
function formatTime(value: string) {
  if (!value) return '暂无记录'
  const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
}
function resetFilters() { search.value = ''; status.value = 'all'; gameFilter.value = null }
function showGameDevices(id: number) { resetFilters(); gameFilter.value = id; activeView.value = 'devices' }
function exportDevices() {
  const safe = (value: string | number) => `"${String(value).replace(/^[=+@\-\t\r]/, "'$&").replaceAll('"', '""')}"`
  const rows = [['设备ID', '设备名称', 'UUID', 'IP地址', '状态', '游戏名称', '场景ID', '更新时间'], ...filteredDevices.value.map(device => [device.id, device.name, device.uuid, device.ip, statusLabels[device.status], device.gameId === null ? '未配置' : gameById(device.gameId)?.name || '未知游戏', device.gameId ?? '', device.updatedAt])]
  const url = URL.createObjectURL(new Blob(['\uFEFF' + rows.map(row => row.map(safe).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8;' }))
  const link = document.createElement('a'); link.href = url; link.download = `不加班-设备列表-${new Date().toLocaleDateString('sv-SE')}.csv`; link.click(); URL.revokeObjectURL(url)
  showToast(`已导出 ${filteredDevices.value.length} 台设备`)
}
let unsubscribeAuthChanges: (() => void) | undefined
onMounted(() => {
  unsubscribeAuthChanges = onAuthIdentityChange(() => {
    viewVersion++
    loadVersion++
    resetWorkspace()
    ready.value = true
    if (configurationReady.value) void connectSession()
  })
  void initializeWorkspace()
})
onBeforeUnmount(() => { unsubscribeAuthChanges?.(); clearTimeout(toastTimeout) })
</script>

<template>
  <div v-if="!ready" class="boot-screen" role="status"><span class="brand-symbol">不<span></span></span><LoaderCircle :size="22" class="spin" /><span>正在连接工作空间…</span></div>
  <LoginView v-else-if="!user" ref="loginView" :busy="loginBusy || connectingSession" :disabled="configurationBusy || !configurationReady" :error="loginError" :demo="demo" :scan-login-enabled="scanLoginEnabled" @login="submitLogin" @authenticated="acceptScanSession" @scan-busy="scanConsumeBusy = $event">
    <template #environment>
      <div v-if="configurationError" class="data-error" role="alert"><CircleX :size="20" /><div><strong>无法加载后端配置</strong><p>{{ configurationError }}</p><button type="button" class="button secondary" :disabled="configurationBusy" @click="initializeWorkspace">{{ configurationBusy ? '正在加载配置…' : '重试加载配置' }}</button></div></div>
    </template>
  </LoginView>
  <div v-else class="app-shell" :inert="!!pickerDevice || helpOpen">
    <div v-if="sidebarOpen" class="sidebar-shade" @click="sidebarOpen = false"></div>
    <aside ref="sidebar" class="sidebar" :class="{ open: sidebarOpen }" @keydown="sidebarKeydown">
      <button class="icon-button sidebar-close" aria-label="关闭导航" @click="sidebarOpen = false"><X :size="20" /></button>
      <a :href="baseUrl" class="brand sidebar-brand" aria-label="不加班设备管理中心"><span class="brand-symbol">不<span></span></span>不加班<span class="brand-caption">BUJIABAN</span></a>
      <div class="workspace-label"><span class="workspace-avatar">不</span><div><strong>不加班工作空间</strong><small>设备管理中心</small></div><ShieldCheck :size="16" /></div>
      <span class="nav-section-label">工作空间</span>
      <nav aria-label="主要导航"><button :class="{ active: activeView === 'devices' }" @click="activeView = 'devices'"><LayoutGrid :size="19" /><span>设备管理</span><span class="nav-count">{{ devices.length }}</span></button><button :class="{ active: activeView === 'games' }" @click="activeView = 'games'"><Gamepad2 :size="20" /><span>游戏库</span></button></nav>
      <div class="sidebar-bottom"><div class="sidebar-tip"><span class="tip-icon"><Sparkles :size="17" /></span><strong>为设备找到合适的游戏</strong><p>在游戏库查看可用游戏，<br />一处完成所有设备配置。</p><button @click="activeView = 'games'">探索游戏库<ArrowRight :size="14" /></button></div><button class="help-nav" @click="helpOpen = true"><CircleHelp :size="18" />使用帮助<ChevronRight :size="15" /></button><div class="sidebar-account"><span class="account-avatar">不</span><div><strong>{{ user.username }}</strong><small>{{ identityLabel }}</small></div><button class="icon-button" aria-label="退出登录" title="退出登录" :disabled="logoutBusy || saving" @click="signOut"><LoaderCircle v-if="logoutBusy" :size="18" class="spin" /><LogOut v-else :size="18" /></button></div></div>
    </aside>
    <div class="main-shell" :inert="sidebarOpen">
      <header class="topbar"><div class="breadcrumb"><button class="icon-button mobile-menu" aria-label="打开导航" @click="sidebarOpen = !sidebarOpen"><Menu :size="21" /></button><span>工作空间</span><ChevronRight :size="13" /><strong>{{ currentTitle }}</strong></div><div class="topbar-right"><span v-if="demo" class="demo-label"><span class="status-dot amber"></span>演示模式</span><span class="topbar-divider"></span><span class="topbar-account">不</span><span class="topbar-name">{{ user.username }}</span></div></header>
      <main class="workspace">
        <div class="page-heading"><div><div class="page-eyebrow">{{ activeView === 'devices' ? 'DEVICE OVERVIEW' : 'GAME LIBRARY' }}</div><h1>{{ currentTitle }}<span class="heading-dot"></span></h1><p>{{ activeView === 'devices' ? (deviceManager ? '你关联的设备，一目了然。配置游戏、欢迎词和图片。' : '所有设备，一目了然。配置游戏、欢迎词和图片。') : (deviceManager ? '发现可用游戏，查看关联设备的游戏配置情况。' : '发现可用游戏，查看每个游戏的设备配置情况。') }}</p></div><button class="button secondary refresh-button" :disabled="loading" @click="loadData"><RefreshCw :size="16" :class="{ spin: loading }" />{{ loading ? '正在同步…' : '刷新数据' }}</button></div>

        <template v-if="activeView === 'devices'">
          <section class="stats-strip" aria-label="设备统计" :aria-busy="loading">
            <div class="stat-item"><div class="stat-label">设备总数<Monitor :size="17" /></div><div class="stat-number">{{ devicesError && !devices.length ? '—' : summary.total }}<span>台</span></div><p><span class="stat-mark"></span>{{ deviceManager ? '统计仅包含你关联的设备' : '当前工作空间全部设备' }}</p></div>
            <div class="stat-item"><div class="stat-label">{{ demo ? '在线设备' : '已使用游戏' }}<component :is="demo ? MonitorCheck : Gamepad2" :size="17" /></div><div class="stat-number">{{ devicesError && !devices.length ? '—' : demo ? summary.online : usedGameCount }}<span>{{ demo ? '台' : '款' }}</span></div><p><span class="status-dot online"></span>{{ demo ? '已连接，可接收游戏配置' : '分配给设备的游戏类型' }}</p></div>
            <div class="stat-item"><div class="stat-label">已配置游戏<Check :size="17" /></div><div class="stat-number">{{ devicesError && !devices.length ? '—' : summary.configured }}<span>台</span></div><p><span class="stat-progress"><i :style="{ width: `${summary.total ? summary.configured / summary.total * 100 : 0}%` }"></i></span>{{ summary.total ? Math.round(summary.configured / summary.total * 100) : 0 }}% 配置完成</p></div>
            <div class="stat-item"><div class="stat-label">待配置设备<Settings2 :size="17" /></div><div class="stat-number">{{ devicesError && !devices.length ? '—' : summary.unconfigured }}<span>台</span></div><button v-if="summary.unconfigured" class="stat-link" @click="status = 'unconfigured'">前往配置<ArrowRight :size="13" /></button><p v-else><span class="status-dot online"></span>{{ summary.total ? (deviceManager ? '关联设备已完成配置' : '全部设备已完成配置') : (deviceManager ? '等待关联设备' : '等待设备接入') }}</p></div>
          </section>

          <div class="section-heading"><div><h2>设备列表 <span>{{ filteredDevices.length }}</span></h2><p>{{ deviceManager ? '仅可查看和配置当前微信账号关联的设备' : '管理设备的游戏、欢迎词与图片配置' }}</p></div><div class="sync-label"><span class="status-dot" :class="devicesError ? 'offline' : 'online'"></span>{{ devicesError ? '同步失败' : `上次同步 ${syncingTime}` }}</div></div>
          <section class="device-panel">
            <div class="device-tabs" role="group" aria-label="筛选设备状态"><button :class="{ active: status === 'all' }" @click="status = 'all'">{{ deviceManager ? '我的设备' : '全部设备' }}<span>{{ summary.total }}</span></button><template v-if="demo"><button :class="{ active: status === 'online' }" @click="status = 'online'"><span class="status-dot online"></span>在线<span>{{ summary.online }}</span></button><button :class="{ active: status === 'offline' }" @click="status = 'offline'"><span class="status-dot offline"></span>离线<span>{{ summary.offline }}</span></button></template><button v-else :class="{ active: status === 'configured' }" @click="status = 'configured'">已配置<span>{{ summary.configured }}</span></button><button :class="{ active: status === 'unconfigured' }" @click="status = 'unconfigured'">待配置<span>{{ summary.unconfigured }}</span></button></div>
            <div class="table-toolbar"><label class="search-input device-search"><Search :size="17" /><input v-model="search" placeholder="搜索设备名称、ID 或 IP 地址" aria-label="搜索设备" /><button v-if="search" class="icon-button" aria-label="清除搜索" @click="search = ''"><X :size="15" /></button></label><div class="toolbar-actions"><label class="select-wrap"><SlidersHorizontal :size="15" /><select v-model="gameFilter" aria-label="按游戏筛选"><option :value="null">全部游戏</option><option v-for="game in games" :key="game.id" :value="game.id">{{ game.name }}</option></select><ChevronDown :size="14" /></label><button class="button export-button" :disabled="loading || !!devicesError || !filteredDevices.length" @click="exportDevices"><ArrowDownToLine :size="16" /><span>导出</span></button></div></div>
            <div v-if="devicesError" class="data-error" role="alert"><CircleX :size="21" /><div><strong>无法同步设备</strong><p>{{ devicesError }}</p></div><button class="button secondary" :disabled="loading" @click="loadData">重试</button></div>
            <div v-if="gamesError" class="inline-warning" role="alert"><CircleHelp :size="16" /><span>游戏目录未能加载，仍可编辑欢迎词和图片。{{ gamesError }}</span><button :disabled="loading" @click="loadData">重试</button></div>
            <div class="table-scroll" :class="{ 'table-loading': loading }" :aria-busy="loading">
              <table><thead><tr><th class="device-col">设备名称</th><th>状态</th><th class="game-col">当前游戏</th><th>IP 地址</th><th>更新时间</th><th class="action-col">操作</th></tr></thead><tbody><tr v-for="device in pageDevices" :key="device.id"><td><div class="device-name-cell"><span class="device-icon" :class="{ muted: device.status === 'offline' }"><Monitor :size="20" :stroke-width="1.6" /></span><span><strong :title="device.name || `设备 #${device.id}`">{{ device.name || `设备 #${device.id}` }}</strong><small class="mono" :title="device.uuid">{{ device.uuid || `DEVICE-${String(device.id).padStart(4, '0')}` }}</small></span></div></td><td><span class="status-badge" :class="device.status"><span class="status-dot" :class="device.status"></span>{{ statusLabels[device.status] }}</span></td><td><div v-if="device.gameId !== null" class="current-game"><GameArtwork :id="device.gameId" /><span><strong>{{ gameById(device.gameId)?.name || `场景 ${device.gameId}` }}</strong><small>SCENE {{ device.gameId }}</small></span></div><button v-else class="unconfigured-label" :disabled="loading || !!devicesError" @click="openPicker(device)"><span class="unconfigured-dash">—</span>尚未配置游戏</button></td><td class="mono ip-cell">{{ device.ip || '—' }}</td><td class="date-cell">{{ formatTime(device.updatedAt) }}</td><td><button class="configure-button" :disabled="loading || !!devicesError" :aria-label="`为${device.name || `设备 ${device.id}`}配置设备`" @click="openPicker(device)"><Settings2 :size="15" />配置设备<ChevronRight :size="14" /></button></td></tr></tbody></table>
              <div v-if="loading && !devices.length" class="empty-state" role="status"><LoaderCircle :size="28" class="spin" /><strong>正在获取设备…</strong></div>
              <div v-else-if="!pageDevices.length && !devicesError" class="empty-state"><span class="empty-icon"><component :is="devices.length ? Search : Unplug" :size="29" :stroke-width="1.5" /></span><strong>{{ devices.length ? '没有找到匹配的设备' : (deviceManager ? '还没有关联设备' : '还没有接入设备') }}</strong><p>{{ devices.length ? '试试其他关键词，或清除筛选条件。' : (deviceManager ? '请先在「不加班」小程序中关联设备，再刷新此列表。' : '设备接入后会显示在这里，点击刷新获取最新列表。') }}</p><button v-if="devices.length" class="button secondary" @click="resetFilters">清除筛选</button></div>
            </div>
            <footer class="table-footer"><span>共 <strong>{{ filteredDevices.length }}</strong> 台设备<span v-if="filteredDevices.length" class="footer-range">，显示 {{ (page - 1) * pageSize + 1 }}–{{ Math.min(page * pageSize, filteredDevices.length) }} 台</span></span><div class="pagination"><label><select v-model="pageSize" aria-label="每页设备数量"><option :value="8">8 条 / 页</option><option :value="12">12 条 / 页</option><option :value="24">24 条 / 页</option></select></label><button class="icon-button" :disabled="page <= 1" aria-label="上一页" @click="page--"><ChevronLeft :size="17" /></button><span class="page-indicator">{{ page }}<span>/ {{ pageCount }}</span></span><button class="icon-button" :disabled="page >= pageCount" aria-label="下一页" @click="page++"><ChevronRight :size="17" /></button></div></footer>
          </section>
          <div class="workspace-note"><ShieldCheck :size="15" /><span>{{ demo ? '本地演示数据，配置保存在此电脑。' : '配置通过服务端保存。设备下次读取时获取新配置；当前暂无心跳状态。' }}</span><button @click="helpOpen = true">了解更多<ArrowRight :size="12" /></button></div>
        </template>

        <template v-else>
          <div class="library-toolbar"><div><span class="library-count">{{ games.length }}</span><span>款可用游戏</span></div><label class="search-input"><Search :size="17" /><input v-model="gameSearch" placeholder="搜索游戏名称或场景 ID" aria-label="搜索游戏库" /></label></div>
          <div v-if="gamesError" class="data-error" role="alert"><CircleX :size="21" /><div><strong>无法加载游戏目录</strong><p>{{ gamesError }}</p></div><button class="button secondary" :disabled="loading" @click="loadData">重试</button></div>
          <div v-if="devicesError" class="inline-warning" role="alert"><CircleHelp :size="16" /><span>设备同步失败，以下配置数量可能不是最新数据。</span><button :disabled="loading" @click="loadData">重试</button></div>
          <div class="game-library"><article v-for="game in filteredGames" :key="game.id" class="library-game"><GameArtwork :id="game.id" large /><div class="library-game-content"><span class="game-category">{{ game.category || '互动游戏' }}</span><h2>{{ game.name }}</h2><p>{{ game.description || '可为设备配置此游戏场景。' }}</p><div class="library-game-footer"><span><Monitor :size="14" />{{ gameDeviceCount(game.id) }} 台设备已配置</span><button :disabled="!!devicesError || loading" :aria-label="`查看使用${game.name}的设备`" @click="showGameDevices(game.id)">查看设备<ArrowRight :size="15" /></button></div></div></article></div>
          <div v-if="!filteredGames.length && !gamesError" class="empty-state"><Gamepad2 :size="32" /><strong>{{ loading ? '正在获取游戏…' : '暂无匹配的游戏' }}</strong><p>{{ gameSearch ? '试试其他搜索关键词。' : '游戏目录为空，请稍后刷新重试。' }}</p></div>
        </template>
        <footer class="workspace-footer"><span>BUJIABAN <span class="footer-slash">/</span> 设备管理中心</span><span>井然有序，尽在掌握<span class="tiny-square"></span></span></footer>
      </main>
    </div>
  </div>
  <Transition name="modal"><GamePicker v-if="pickerDevice" :device="pickerDevice" :games="games" :busy="saving" :error="saveError" :games-error="gamesError" @close="pickerDevice = null" @save="saveConfiguration" /></Transition>
  <Transition name="toast"><div v-if="toast" class="toast-message" :class="{ error: toast.error }" role="status"><CircleX v-if="toast.error" :size="18" /><Check v-else :size="18" /><span>{{ toast.text }}</span><button class="icon-button" aria-label="关闭提示" @click="toast = null"><X :size="15" /></button></div></Transition>
  <Transition name="modal"><HelpDialog v-if="helpOpen" :demo="demo" :device-manager="deviceManager" @close="helpOpen = false" /></Transition>
</template>
