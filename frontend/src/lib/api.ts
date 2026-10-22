import type { Device, DeviceConfigurationPatch, DeviceImagePreview, Game, ScanLoginTicket, ScanLoginTicketStatus, Session } from '../types'

export const isDemoMode = import.meta.env.MODE === 'demo'
interface ApiConfiguration { readonly apiBase: '/api/admin-api' | '/admin-api'; readonly automatic: boolean; readonly auth: 'jwt' | 'session' }
const demoConfiguration: ApiConfiguration = Object.freeze({ apiBase: '/admin-api', automatic: false, auth: 'session' })
let configuration: ApiConfiguration | undefined
let initialization: Promise<ApiConfiguration> | undefined
let csrfToken: string | undefined
const TOKEN_KEY = 'game-admin-jwt-v1'
const OWNER_KEY = 'game-admin-scan-owner-v1'
type Tokens = NonNullable<Session['token']>
let tokens: Tokens | undefined
let tokensLoaded = false
let storageMemoryOnly = false
let tokenGeneration = 0
let authGeneration = 0
let refreshFlight: { identity: number; promise: Promise<void> } | undefined
let currentOwnerProof: string | undefined
const authChangeListeners = new Set<() => void>()
// Session mutations invalidate earlier reads and errors before they can alter a newer token.
let sessionVersion = 0
let sessionReadVersion = 0

function apiBase(): string {
  if (isDemoMode) return demoConfiguration.apiBase
  if (!configuration) throw new ApiError('后端配置尚未就绪，请重试加载配置', 0)
  return configuration.apiBase
}

export class ApiError extends Error {
  constructor(message: string, public readonly status: number) {
    super(message)
    this.name = 'ApiError'
  }
}

export function onAuthIdentityChange(listener: () => void): () => void {
  authChangeListeners.add(listener)
  return () => authChangeListeners.delete(listener)
}

function notifyAuthIdentityChange(): void {
  for (const listener of authChangeListeners) listener()
}

function staleAuthError(): ApiError { return new ApiError('登录状态已更新，请重试当前操作', 0) }

function beginIdentityChange(): { auth: number; session: number } {
  authGeneration++
  sessionVersion++
  return { auth: authGeneration, session: sessionVersion }
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

function decodeConfiguration(value: unknown): ApiConfiguration {
  if (!isRecord(value) || value.apiBase !== '/api/admin-api' || value.automatic !== true || value.auth !== 'jwt') throw invalidConfiguration()
  return Object.freeze({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' })
}

function invalidConfiguration(): ApiError {
  return new ApiError('后端配置格式无效，请检查配置后重试加载', 502)
}

async function loadConfiguration(): Promise<ApiConfiguration> {
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
  return decodeConfiguration(body)
}

export async function initializeApi(): Promise<ApiConfiguration> {
  if (isDemoMode) return demoConfiguration
  if (configuration) return configuration
  if (!initialization) {
    initialization = loadConfiguration().then(value => {
      configuration = value
      return value
    }).finally(() => { initialization = undefined })
  }
  return initialization
}

function isSession(value: unknown): value is Session {
  return isRecord(value)
    && (value.authMode === undefined || value.authMode === 'jwt' || value.authMode === 'session')
    && (value.csrfToken === undefined || (typeof value.csrfToken === 'string' && value.csrfToken.length > 0))
    && (value.token === undefined || isTokens(value.token))
    && (value.user === null || (isRecord(value.user) && typeof value.user.username === 'string' && value.user.username.length > 0
      && (value.user.role === undefined || value.user.role === 'admin' || value.user.role === 'device_manager')))
    && (value.demo === undefined || typeof value.demo === 'boolean')
    && (value.scanLoginEnabled === undefined || typeof value.scanLoginEnabled === 'boolean')
}

function isTokens(value: unknown): value is Tokens {
  return isRecord(value) && typeof value.accessToken === 'string' && value.accessToken.length > 0
    && typeof value.refreshToken === 'string' && value.refreshToken.length > 0
    && isIsoDate(value.expires) && isIsoDate(value.refreshExpires)
}

function tokenFamily(value: Tokens | undefined): string | undefined {
  if (!value) return undefined
  const separator = value.refreshToken.indexOf('.')
  return separator > 0 ? value.refreshToken.slice(0, separator) : value.refreshToken
}

function sameTokenFamily(left: Tokens | undefined, right: Tokens | undefined): boolean {
  return tokenFamily(left) !== undefined && tokenFamily(left) === tokenFamily(right)
}

function readPersistedTokens(): Tokens | undefined {
  try {
    const raw = globalThis.localStorage?.getItem(TOKEN_KEY)
    const value: unknown = raw ? JSON.parse(raw) : undefined
    return isTokens(value) && Date.parse(value.refreshExpires) > Date.now() ? value : undefined
  } catch { return undefined }
}

function loadTokens(): void {
  if (tokensLoaded || configuration?.auth !== 'jwt') return
  tokensLoaded = true
  try {
    const raw = globalThis.localStorage?.getItem(TOKEN_KEY)
    if (raw) {
      const value: unknown = JSON.parse(raw)
      if (isTokens(value) && Date.parse(value.refreshExpires) > Date.now()) tokens = value
      else globalThis.localStorage?.removeItem(TOKEN_KEY)
    }
  } catch { tokens = undefined }
}

function saveTokens(value: Tokens | undefined): void {
  tokens = value
  tokensLoaded = true
  tokenGeneration++
  try {
    if (value) globalThis.localStorage?.setItem(TOKEN_KEY, JSON.stringify(value))
    else globalThis.localStorage?.removeItem(TOKEN_KEY)
  } catch {
    if (value) {
      storageMemoryOnly = true
      try { globalThis.localStorage?.removeItem(TOKEN_KEY) } catch { /* Keep only the in-memory token. */ }
    }
  }
}

function acceptStorageToken(): void {
  if (storageMemoryOnly) return
  const previous = tokens
  const next = readPersistedTokens()
  if (sameTokenFamily(previous, next)) {
    tokens = next
    tokensLoaded = true
    tokenGeneration++
    return
  }
  tokens = next
  tokensLoaded = true
  tokenGeneration++
  authGeneration++
  sessionVersion++
  notifyAuthIdentityChange()
}

globalThis.addEventListener?.('storage', event => {
  const storageEvent = event as StorageEvent
  if (storageEvent.key === TOKEN_KEY || storageEvent.key === null) acceptStorageToken()
})

function ownerProof(): string {
  if (currentOwnerProof) return currentOwnerProof
  let storage: Storage | undefined
  try {
    storage = globalThis.sessionStorage
    const existing = storage?.getItem(OWNER_KEY)
    if (existing && /^[a-f0-9]{64}$/.test(existing)) { currentOwnerProof = existing; return existing }
  } catch { /* Generate an in-memory proof when sessionStorage is blocked. */ }
  try {
    const bytes = new Uint8Array(32)
    globalThis.crypto.getRandomValues(bytes)
    const proof = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')
    currentOwnerProof = proof
    try { storage?.setItem(OWNER_KEY, proof) } catch { /* Keep the proof stable in this tab's memory. */ }
    return proof
  } catch { throw new ApiError('无法安全初始化扫码验证，请刷新页面后重试', 0) }
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

function requireScanLogin(): void {
  if (isDemoMode) throw new ApiError('演示环境不支持扫码登录', 0)
  apiBase()
}

function scanLoginPath(ticketId: string): string {
  if (!/^[a-f0-9]{32}$/.test(ticketId)) throw invalidResponse()
  return `/scan-login/tickets/${ticketId}`
}

function isDevice(value: unknown): value is Device {
  return isRecord(value)
    && Number.isSafeInteger(value.id)
    && typeof value.uuid === 'string'
    && (value.version === undefined || (typeof value.version === 'number'
      && Number.isInteger(value.version) && value.version >= 0 && value.version <= 2147483647))
    && typeof value.name === 'string'
    && (value.title === undefined || typeof value.title === 'string')
    && typeof value.ip === 'string'
    && typeof value.status === 'string' && ['online', 'offline', 'unknown'].includes(value.status)
    && typeof value.updatedAt === 'string'
    && (value.gameId === null || Number.isSafeInteger(value.gameId))
    && ['slogans', 'pictures', 'thumbs'].every(field => value[field] === undefined
      || (Array.isArray(value[field]) && value[field].every(item => typeof item === 'string')))
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

function decodeJwtSession(value: unknown): Session {
  const session = decode(value, isSession)
  if (session.authMode !== 'jwt') throw invalidResponse()
  return session
}

async function request(path: string, init: RequestInit = {}): Promise<unknown> {
  const base = apiBase()
  const jwt = configuration?.auth === 'jwt' && !isDemoMode
  const identityAtStart = authGeneration
  if (jwt && !['/login', '/refresh', '/logout'].includes(path)) {
    loadTokens()
    if (tokens && Date.parse(tokens.expires) <= Date.now() + 60_000) await refreshTokens()
  }
  if (jwt && identityAtStart !== authGeneration) throw staleAuthError()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')
  if (jwt) {
    loadTokens()
    if (tokens) headers.set('Authorization', `Bearer ${tokens.accessToken}`)
    else headers.delete('Authorization')
    if (init.headers && new Headers(init.headers).has('X-Admin-Scan-Owner')) headers.set('X-Admin-Scan-Owner', ownerProof())
  } else if (csrfToken && init.method && init.method !== 'GET') headers.set('X-CSRF-Token', csrfToken)
  const requestTokenGeneration = tokenGeneration
  const requestAccessToken = headers.get('Authorization')?.replace(/^Bearer\s+/i, '')

  let result: Response
  try {
    result = await fetch(`${base}${path}`, {
      ...init,
      headers,
      credentials: jwt ? 'omit' : 'same-origin',
      signal: init.signal ?? AbortSignal.timeout(45_000),
    })
  } catch (error) {
    if (jwt && (identityAtStart !== authGeneration || requestTokenGeneration !== tokenGeneration)) throw staleAuthError()
    const timedOut = error instanceof Error && ['TimeoutError', 'AbortError'].includes(error.name)
    throw new ApiError(timedOut ? '请求超时，请稍后重试' : '无法连接服务器，请检查网络连接', 0)
  }

  if (jwt && (identityAtStart !== authGeneration || requestTokenGeneration !== tokenGeneration)) throw staleAuthError()

  let body: unknown
  try {
    body = await result.json()
  } catch {
    if (result.ok) throw invalidResponse()
    body = null
  }
  if (jwt && (identityAtStart !== authGeneration || requestTokenGeneration !== tokenGeneration)) throw staleAuthError()
  if (!result.ok) {
    if (result.status === 401 && jwt && requestTokenGeneration === tokenGeneration && tokens?.accessToken === requestAccessToken) saveTokens(undefined)
    if ((result.status === 401 || result.status === 403) && !jwt) {
      csrfToken = undefined
      sessionVersion++
    }
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

async function fetchSession(): Promise<Session> {
  loadTokens()
  const hadStoredToken = configuration?.auth === 'jwt' && !isDemoMode && Boolean(tokens)
  const identity = authGeneration
  const generation = tokenGeneration
  const version = sessionVersion
  const read = ++sessionReadVersion
  let responseBody: unknown
  try { responseBody = await request('/session') }
  catch (error) {
    // Recover scan-login capability after a revoked stored credential. This
    // anonymous identity read is safe; business reads and writes are never replayed.
    if (!hadStoredToken || !(error instanceof ApiError) || error.status !== 401) throw error
    responseBody = await request('/session')
  }
  if (identity !== authGeneration || version !== sessionVersion) throw staleAuthError()
  const session = configuration?.auth === 'jwt' && !isDemoMode ? decodeJwtSession(responseBody) : decode(responseBody, isSession)
  if (version === sessionVersion && read === sessionReadVersion) csrfToken = session.csrfToken
  if (session.token && configuration?.auth === 'jwt' && generation === tokenGeneration) saveTokens(session.token)
  return session
}

async function refreshTokens(): Promise<void> {
  loadTokens()
  const previous = tokens
  if (!previous) return
  if (Date.parse(previous.refreshExpires) <= Date.now()) { saveTokens(undefined); return }
  const identity = authGeneration
  if (refreshFlight?.identity === identity) return refreshFlight.promise
  const promise = (async () => {
    const refreshUnderLock = async () => {
      let current = readPersistedTokens()
      if (!current) current = tokens
      if (sameTokenFamily(previous, current) && current!.refreshToken !== previous.refreshToken
        && Date.parse(current!.expires) > Date.now() + 60_000) {
        tokens = current
        tokensLoaded = true
        tokenGeneration++
        return
      }
      if (tokenFamily(current) !== tokenFamily(previous)) throw staleAuthError()
      const responseBody = await request('/refresh', {
        method: 'POST', body: JSON.stringify({ refreshToken: current?.refreshToken ?? previous.refreshToken }),
      })
      const result = decodeJwtSession(responseBody)
      if (!result.token || !result.user || result.authMode !== 'jwt') throw invalidResponse()
      if (identity !== authGeneration) throw staleAuthError()
      saveTokens(result.token)
    }
    const locks = typeof navigator !== 'undefined' ? navigator.locks : undefined
    if (locks?.request) await locks.request('game-admin-refresh-v1', refreshUnderLock)
    else await refreshUnderLock()
  })().finally(() => { if (refreshFlight?.promise === promise) refreshFlight = undefined })
  refreshFlight = { identity, promise }
  return promise
}

function assertCurrentSession(version: number): void {
  if (version !== sessionVersion) throw new ApiError('登录状态已更新，请重试当前操作', 0)
}

function acceptSession(session: Session, version: number): Session {
  assertCurrentSession(version)
  csrfToken = session.csrfToken
  sessionVersion++
  return session
}

async function write(path: string, method: string, body?: unknown, cache?: RequestCache): Promise<unknown> {
  apiBase()
  const jwt = configuration?.auth === 'jwt' && !isDemoMode
  const version = sessionVersion
  if (!jwt && !csrfToken) await fetchSession()
  assertCurrentSession(version)
  return request(path, {
    method,
    headers: jwt ? undefined : { 'X-CSRF-Token': csrfToken! },
    ...(cache ? { cache } : {}),
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  })
}

export async function getSession(): Promise<Session> { return fetchSession() }

export async function login(username: string, password: string): Promise<Session> {
  apiBase()
  if (isDemoMode || configuration?.auth !== 'jwt') {
    const version = ++sessionVersion
    return acceptSession(decode(await write('/login', 'POST', { username, password }), isSession), version)
  }
  loadTokens()
  const identity = beginIdentityChange()
  tokenGeneration++
  const value = decodeJwtSession(await request('/login', { method: 'POST', body: JSON.stringify({ username, password }), headers: { 'X-Admin-Scan-Owner': 'required' } }))
  if (identity.auth !== authGeneration || identity.session !== sessionVersion) throw staleAuthError()
  if (configuration?.auth === 'jwt') {
    if (!value.token || !value.user || value.authMode !== 'jwt') throw invalidResponse()
    saveTokens(value.token)
    return value
  }
  return value
}

export async function logout(): Promise<void> {
  apiBase()
  if (configuration?.auth === 'jwt' && !isDemoMode) {
    loadTokens()
    const current = tokens
    const identity = beginIdentityChange()
    tokenGeneration++
    if (!current) { saveTokens(undefined); return }
    const result = decodeJwtSession(await request('/logout', { method: 'POST', body: JSON.stringify({ refreshToken: current.refreshToken }), headers: { 'X-Admin-Scan-Owner': 'required' } }))
    if (result.user !== null) throw invalidResponse()
    if (identity.auth !== authGeneration || identity.session !== sessionVersion) throw staleAuthError()
    saveTokens(undefined)
    return
  }
  const version = ++sessionVersion
  await write('/logout', 'POST')
  assertCurrentSession(version)
  csrfToken = undefined
  sessionVersion++
}

export async function createScanLoginTicket(): Promise<ScanLoginTicket> {
  requireScanLogin()
  if (isDemoMode || configuration?.auth !== 'jwt') return decode(await write('/scan-login/tickets', 'POST', undefined, 'no-store'), isScanLoginTicket)
  return decode(await request('/scan-login/tickets', { method: 'POST', cache: 'no-store', headers: { 'X-Admin-Scan-Owner': 'required' } }), isScanLoginTicket)
}

export async function getScanLoginTicket(ticketId: string): Promise<ScanLoginTicketStatus> {
  requireScanLogin()
  if (isDemoMode || configuration?.auth !== 'jwt') return decode(await request(scanLoginPath(ticketId), { cache: 'no-store' }), isScanLoginStatus)
  return decode(await request(scanLoginPath(ticketId), { cache: 'no-store', headers: { 'X-Admin-Scan-Owner': 'required' } }), isScanLoginStatus)
}

export async function consumeScanLoginTicket(ticketId: string): Promise<Session> {
  requireScanLogin()
  const path = `${scanLoginPath(ticketId)}/consume`
  if (isDemoMode || configuration?.auth !== 'jwt') {
    const version = ++sessionVersion
    const legacy = decode(await write(path, 'POST', undefined, 'no-store'), isSession)
    if (!legacy.user) throw invalidResponse()
    return acceptSession(legacy, version)
  }
  const identity = beginIdentityChange()
  tokenGeneration++
  const session = decodeJwtSession(await request(path, { method: 'POST', cache: 'no-store', headers: { 'X-Admin-Scan-Owner': 'required' } }))
  if (!session.user) throw invalidResponse()
  if (configuration?.auth === 'jwt') {
    if (!session.token || session.authMode !== 'jwt') throw invalidResponse()
    if (identity.auth !== authGeneration || identity.session !== sessionVersion) throw staleAuthError()
    saveTokens(session.token)
    return session
  }
  return session
}

type ScanLoginCancellation = { status: 'cancelled' | 'expired' | 'consumed'; expiresAt?: string }
export async function cancelScanLoginTicket(ticketId: string): Promise<ScanLoginCancellation> {
  requireScanLogin()
  const path = `${scanLoginPath(ticketId)}/cancel`
  const result = isDemoMode || configuration?.auth !== 'jwt'
    ? await write(path, 'POST', undefined, 'no-store')
    : await request(path, { method: 'POST', cache: 'no-store', headers: { 'X-Admin-Scan-Owner': 'required' } })
  return decode(result,
    (value): value is ScanLoginCancellation => isRecord(value) && typeof value.status === 'string'
      && ['cancelled', 'expired', 'consumed'].includes(value.status)
      && (value.expiresAt === undefined || isIsoDate(value.expiresAt)))
}

export async function getDevices(): Promise<Device[]> {
  return decode(await request('/devices'), isDeviceList)
}

export async function getGames(): Promise<Game[]> {
  return decode(await request('/games'), isGameList)
}

function isImagePreview(value: unknown): value is DeviceImagePreview {
  if (!isRecord(value) || typeof value.source !== 'string' || typeof value.url !== 'string'
    || !Number.isSafeInteger(value.expiresAt) || (value.expiresAt as number) <= 0) return false
  try {
    const source = new URL(value.source), url = new URL(value.url)
    return source.protocol === 'https:' && url.protocol === 'https:'
      && !source.username && !source.password && !url.username && !url.password
      && !url.hash && source.origin === url.origin
      && decodeURIComponent(source.pathname) === decodeURIComponent(url.pathname)
  } catch { return false }
}

export async function getDeviceImagePreviews(deviceId: number, signal?: AbortSignal): Promise<DeviceImagePreview[]> {
  if (!Number.isSafeInteger(deviceId) || deviceId < 1) throw invalidResponse()
  const result = decode(await request(`/devices/${deviceId}/image-previews`, {
    cache: 'no-store', headers: { 'X-Admin-Scan-Owner': 'required' },
    ...(signal ? { signal: AbortSignal.any([signal, AbortSignal.timeout(45_000)]) } : {}),
  }), (value): value is { previews: DeviceImagePreview[] } => isRecord(value)
    && Array.isArray(value.previews) && value.previews.every(isImagePreview))
  return result.previews
}

export async function assignGame(deviceId: number, gameId: number): Promise<Device> {
  return decode(await write(`/devices/${deviceId}/game`, 'PUT', { gameId }), isDevice)
}

export async function updateDeviceConfiguration(deviceId: number, patch: DeviceConfigurationPatch): Promise<Device> {
  return decode(await write(`/devices/${deviceId}/configuration`, 'PUT', patch), isDevice)
}

export async function deleteDevice(deviceId: number): Promise<void> {
  const result = await write(`/devices/${deviceId}`, 'DELETE')
  if (!isRecord(result) || result.id !== deviceId || result.deleted !== true) throw invalidResponse()
}
