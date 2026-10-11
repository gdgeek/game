import type { BackendId, BackendOption, Device, Game, Session } from '../types'

export const isDemoMode = import.meta.env.MODE === 'demo'
export const BACKENDS: readonly BackendOption[] = [
  { id: 'b0', name: '正式后端 b0', host: 'x.b0.01xr.com' },
  { id: 'b1', name: '正式后端 b1', host: 'x.b1.01xr.com' },
]
const BACKEND_STORAGE_KEY = 'bujiaban.checkin.backend'
const csrfTokens = new Map<BackendId | 'demo', string>()
let selectedBackend: BackendId = readSelectedBackend()

function readSelectedBackend(): BackendId {
  try {
    return localStorage.getItem(BACKEND_STORAGE_KEY) === 'b1' ? 'b1' : 'b0'
  } catch {
    return 'b0'
  }
}

export function getSelectedBackend(): BackendId { return selectedBackend }

export function setSelectedBackend(backend: BackendId): void {
  selectedBackend = backend
  try { localStorage.setItem(BACKEND_STORAGE_KEY, backend) } catch { /* Storage may be unavailable in private browsers. */ }
}

interface ApiContext {
  backend: BackendId | 'demo'
  base: string
}

function currentContext(): ApiContext {
  return isDemoMode
    ? { backend: 'demo', base: '/admin-api' }
    : { backend: selectedBackend, base: `/backends/${selectedBackend}/admin-api` }
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

function isSession(value: unknown): value is Session {
  return isRecord(value)
    && typeof value.csrfToken === 'string' && value.csrfToken.length > 0
    && (value.user === null || (isRecord(value.user) && typeof value.user.username === 'string' && value.user.username.length > 0))
    && (value.demo === undefined || typeof value.demo === 'boolean')
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

async function write(context: ApiContext, path: string, method: string, body?: unknown): Promise<unknown> {
  if (!csrfTokens.has(context.backend)) await fetchSession(context)
  return request(context, path, {
    method,
    headers: { 'X-CSRF-Token': csrfTokens.get(context.backend)! },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  })
}

export async function getSession(): Promise<Session> {
  return fetchSession(currentContext())
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

export async function getDevices(): Promise<Device[]> {
  return decode(await request(currentContext(), '/devices'), isDeviceList)
}

export async function getGames(): Promise<Game[]> {
  return decode(await request(currentContext(), '/games'), isGameList)
}

export async function assignGame(deviceId: number, gameId: number): Promise<Device> {
  return decode(await write(currentContext(), `/devices/${deviceId}/game`, 'PUT', { gameId }), isDevice)
}
