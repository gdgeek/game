import type { BackendId, BackendOption, Device, Game, ScanLoginTicket, ScanLoginTicketStatus, Session } from '../types'

export const isDemoMode = import.meta.env.MODE === 'demo'
const BACKEND_STORAGE_KEY = 'bujiaban.checkin.backend'
const csrfTokens = new Map<BackendId | 'demo', string>()
const emptyBackends: readonly BackendOption[] = Object.freeze([])
let backends: readonly BackendOption[] | undefined
let initialization: Promise<readonly BackendOption[]> | undefined
let selectedBackend: BackendId = ''

function readSelectedBackend(): string | null {
  try {
    return localStorage.getItem(BACKEND_STORAGE_KEY)
  } catch {
    return null
  }
}

export function getSelectedBackend(): BackendId { return selectedBackend }

export function setSelectedBackend(backend: BackendId): void {
  if (!backends?.some(option => option.id === backend)) throw new ApiError('所选后端不在当前后端配置中，请重新加载配置', 0)
  selectedBackend = backend
  try { localStorage.setItem(BACKEND_STORAGE_KEY, backend) } catch { /* Storage may be unavailable in private browsers. */ }
}

interface ApiContext {
  backend: BackendId | 'demo'
  base: string
}

function currentContext(backend: BackendId = selectedBackend): ApiContext {
  if (isDemoMode) return { backend: 'demo', base: '/admin-api' }
  if (!backends || !backend) throw new ApiError('后端配置尚未就绪，请重试加载配置', 0)
  if (!backends.some(option => option.id === backend)) throw new ApiError('所选后端不在当前后端配置中，请重新加载配置', 0)
  return { backend, base: `/api/backends/${backend}/admin-api` }
}

export class ApiError extends Error {
  constructor(message: string, public readonly status: number) {
    super(message)
    this.name = 'ApiError'
  }
}

function fallbackMessage(status: number): string {
  if (status === 401) return '登录已过期，请重新登录'
  if (status === 403) return '会话校验失败，请刷新页面后重试'
  if (status === 422) return '提交的信息有误，请检查后重试'
  return '服务暂时不可用，请稍后重试'
}

function invalidResponse(): ApiError {
  return new ApiError('服务返回的数据格式不正确，请重试', 502)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function safeHost(value: unknown): value is string {
  if (typeof value !== 'string') return false
  const [hostname, port, ...extra] = value.split(':')
  return !!hostname && hostname.length <= 253 && extra.length === 0
    && hostname.split('.').every(label => /^[a-z\d](?:[a-z\d-]{0,61}[a-z\d])?$/i.test(label))
    && (port === undefined || (/^[1-9]\d{0,4}$/.test(port) && Number(port) <= 65535))
}

function decodeBackends(value: unknown): readonly BackendOption[] {
  if (!isRecord(value) || !Array.isArray(value.backends) || !value.backends.length) throw invalidConfiguration()
  const ids = new Set<string>()
  const options = value.backends.map((option: unknown): BackendOption => {
    if (!isRecord(option)
      || typeof option.id !== 'string' || !/^b(0|[1-9][0-9]*)$/.test(option.id)
      || !Number.isSafeInteger(Number(option.id.slice(1))) || ids.has(option.id)
      || typeof option.name !== 'string' || !option.name.length || option.name.length > 120
      || option.name.trim() !== option.name || /[\u0000-\u001f\u007f-\u009f<>]/.test(option.name)
      || !safeHost(option.host)) throw invalidConfiguration()
    ids.add(option.id)
    return Object.freeze({ id: option.id, name: option.name, host: option.host })
  })
  return Object.freeze(options)
}

function invalidConfiguration(): ApiError {
  return new ApiError('后端配置格式无效，请检查配置后重试加载', 502)
}

async function loadBackends(): Promise<readonly BackendOption[]> {
  let result: Response
  try {
    result = await fetch('/runtime-config.json', {
      cache: 'no-store', credentials: 'same-origin', mode: 'same-origin',
      headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(15_000),
    })
  } catch (error) {
    const timedOut = error instanceof Error && ['TimeoutError', 'AbortError'].includes(error.name)
    throw new ApiError(timedOut ? '后端配置加载超时，请重试加载配置' : '无法加载后端配置，请检查网络后重试加载配置', 0)
  }
  if (!result.ok) throw new ApiError(`后端配置加载失败（HTTP ${result.status}），请重试加载配置`, result.status)
  let body: unknown
  try { body = await result.json() } catch { throw invalidConfiguration() }
  return decodeBackends(body)
}

export async function initializeBackends(): Promise<readonly BackendOption[]> {
  if (isDemoMode) return emptyBackends
  if (backends) return backends
  if (!initialization) {
    initialization = loadBackends().then(options => {
      backends = options
      const saved = readSelectedBackend()
      setSelectedBackend(options.some(option => option.id === saved) ? saved! : options[0]!.id)
      return options
    }).finally(() => { initialization = undefined })
  }
  return initialization
}

function isSession(value: unknown): value is Session {
  return isRecord(value)
    && typeof value.csrfToken === 'string' && value.csrfToken.length > 0
    && (value.user === null || (isRecord(value.user) && typeof value.user.username === 'string' && value.user.username.length > 0
      && (value.user.role === undefined || value.user.role === 'admin' || value.user.role === 'device_manager')))
    && (value.demo === undefined || typeof value.demo === 'boolean')
    && (value.scanLoginEnabled === undefined || typeof value.scanLoginEnabled === 'boolean')
}

function isIsoDate(value: unknown): value is string {
  return typeof value === 'string'
    && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value)
    && Number.isFinite(Date.parse(value))
}

function isScanLoginTicket(value: unknown): value is ScanLoginTicket {
  if (!isRecord(value) || typeof value.ticketId !== 'string' || !/^[a-f0-9]{32}$/.test(value.ticketId)
    || typeof value.qrText !== 'string' || !isIsoDate(value.expiresAt)) return false
  const match = value.qrText.match(/^bujiaban-admin-login:\/\/v1\/([a-f0-9]{32})\?secret=[a-f0-9]{64}&origin=([^&]+)$/)
  if (!match || match[1] !== value.ticketId) return false
  try {
    const origin = decodeURIComponent(match[2]!)
    const url = new URL(origin)
    return url.protocol === 'https:' && url.origin === origin && encodeURIComponent(origin) === match[2]
  } catch { return false }
}

function isScanLoginStatus(value: unknown): value is ScanLoginTicketStatus {
  return isRecord(value) && typeof value.status === 'string'
    && ['pending', 'approved', 'consumed', 'cancelled', 'expired'].includes(value.status)
    && isIsoDate(value.expiresAt)
}

function scanLoginContext(backend?: BackendId): ApiContext {
  if (isDemoMode) throw new ApiError('演示环境不支持扫码登录', 0)
  return currentContext(backend)
}

function scanLoginPath(ticketId: string): string {
  if (!/^[a-f0-9]{32}$/.test(ticketId)) throw invalidResponse()
  return `/scan-login/tickets/${ticketId}`
}

function isDevice(value: unknown): value is Device {
  return isRecord(value)
    && Number.isSafeInteger(value.id)
    && typeof value.uuid === 'string'
    && typeof value.name === 'string'
    && typeof value.ip === 'string'
    && typeof value.status === 'string' && ['online', 'offline', 'unknown'].includes(value.status)
    && typeof value.updatedAt === 'string'
    && (value.gameId === null || Number.isSafeInteger(value.gameId))
}

function isGame(value: unknown): value is Game {
  return isRecord(value)
    && Number.isSafeInteger(value.id)
    && typeof value.name === 'string'
    && typeof value.description === 'string'
    && typeof value.category === 'string'
}

function isDeviceList(value: unknown): value is Device[] {
  return Array.isArray(value) && value.every(isDevice)
}

function isGameList(value: unknown): value is Game[] {
  return Array.isArray(value) && value.every(isGame)
}

function decode<T>(body: unknown, validate: (value: unknown) => value is T): T {
  if (!validate(body)) throw invalidResponse()
  return body
}

async function request(context: ApiContext, path: string, init: RequestInit = {}): Promise<unknown> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')

  let result: Response
  try {
    result = await fetch(`${context.base}${path}`, {
      ...init,
      headers,
      credentials: 'same-origin',
      signal: init.signal ?? AbortSignal.timeout(15_000),
    })
  } catch (error) {
    const timedOut = error instanceof Error && ['TimeoutError', 'AbortError'].includes(error.name)
    throw new ApiError(timedOut ? '请求超时，请稍后重试' : '无法连接服务器，请检查网络连接', 0)
  }

  let body: unknown
  try {
    body = await result.json()
  } catch {
    if (result.ok) throw invalidResponse()
    body = null
  }
  if (!result.ok) {
    if (result.status === 401 || result.status === 403) csrfTokens.delete(context.backend)
    if (result.status === 404 && ['/session', '/login', '/logout', '/devices', '/games'].includes(path)) {
      throw new ApiError('该后端尚未部署管理接口', 404)
    }
    const serverMessage = body && typeof body === 'object' && 'message' in body && typeof body.message === 'string'
      ? body.message
      : fallbackMessage(result.status)
    throw new ApiError(serverMessage, result.status)
  }
  return body
}

async function fetchSession(context: ApiContext): Promise<Session> {
  const session = decode(await request(context, '/session'), isSession)
  csrfTokens.set(context.backend, session.csrfToken)
  return session
}

async function write(context: ApiContext, path: string, method: string, body?: unknown, cache?: RequestCache): Promise<unknown> {
  if (!csrfTokens.has(context.backend)) await fetchSession(context)
  return request(context, path, {
    method,
    headers: { 'X-CSRF-Token': csrfTokens.get(context.backend)! },
    ...(cache ? { cache } : {}),
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  })
}

export async function getSession(backend?: BackendId): Promise<Session> {
  return fetchSession(currentContext(backend))
}

export async function login(username: string, password: string): Promise<Session> {
  const context = currentContext()
  const session = decode(await write(context, '/login', 'POST', { username, password }), isSession)
  csrfTokens.set(context.backend, session.csrfToken)
  return session
}

export async function logout(): Promise<void> {
  const context = currentContext()
  await write(context, '/logout', 'POST')
  csrfTokens.delete(context.backend)
}

// The caller captures its backend when opening the scanner, including cleanup requests.
export async function createScanLoginTicket(backend?: BackendId): Promise<ScanLoginTicket> {
  const context = scanLoginContext(backend)
  return decode(await write(context, '/scan-login/tickets', 'POST', undefined, 'no-store'), isScanLoginTicket)
}

export async function getScanLoginTicket(ticketId: string, backend?: BackendId): Promise<ScanLoginTicketStatus> {
  const context = scanLoginContext(backend)
  return decode(await request(context, scanLoginPath(ticketId), { cache: 'no-store' }), isScanLoginStatus)
}

export async function consumeScanLoginTicket(ticketId: string, backend?: BackendId): Promise<Session> {
  const context = scanLoginContext(backend)
  const session = decode(await write(context, `${scanLoginPath(ticketId)}/consume`, 'POST', undefined, 'no-store'), isSession)
  if (!session.user) throw invalidResponse()
  csrfTokens.set(context.backend, session.csrfToken)
  return session
}

type ScanLoginCancellation = { status: 'cancelled' | 'expired' | 'consumed'; expiresAt?: string }
export async function cancelScanLoginTicket(ticketId: string, backend?: BackendId): Promise<ScanLoginCancellation> {
  const context = scanLoginContext(backend)
  return decode(await write(context, `${scanLoginPath(ticketId)}/cancel`, 'POST', undefined, 'no-store'),
    (value): value is ScanLoginCancellation => isRecord(value) && typeof value.status === 'string'
      && ['cancelled', 'expired', 'consumed'].includes(value.status)
      && (value.expiresAt === undefined || isIsoDate(value.expiresAt)))
}

export async function getDevices(): Promise<Device[]> {
  return decode(await request(currentContext(), '/devices'), isDeviceList)
}

export async function getGames(): Promise<Game[]> {
  return decode(await request(currentContext(), '/games'), isGameList)
}

export async function assignGame(deviceId: number, gameId: number): Promise<Device> {
  return decode(await write(currentContext(), `/devices/${deviceId}/game`, 'PUT', { gameId }), isDevice)
}
