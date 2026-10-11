import { pbkdf2Sync, randomBytes, timingSafeEqual } from 'node:crypto'
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { dirname, resolve } from 'node:path'
import type { Plugin } from 'vite'
import type { Device, Game, Session } from '../src/types'

const COOKIE_NAME = 'dirui_demo_session'
const SESSION_TTL = 8 * 60 * 60 * 1_000
const PASSWORD_SALT = 'orbit-console-demo-admin-v1'
const PASSWORD_HASH = Buffer.from('5bef5fd9af8bf6ce871e66fb9e9eb820c257cb2f1b5d9acc71535a51437db56b', 'hex')

const games: Game[] = [
  { id: 638, name: '星际穿越', description: '驾驶飞船穿越星际，探索未知宇宙。', category: '科幻' },
  { id: 639, name: '极速狂飙', description: '沉浸式赛道驾驶，感受速度与激情。', category: '竞速' },
  { id: 640, name: '深海探险', description: '潜入神秘深海，发现海底世界。', category: '探索' },
  { id: 641, name: '节奏光年', description: '跟随音乐节奏，击打光影方块。', category: '音乐' },
  { id: 642, name: '丛林奇遇', description: '走进奇幻丛林，开启冒险旅程。', category: '冒险' },
  { id: 643, name: '篮球达人', description: '精准投篮挑战，刷新个人最佳成绩。', category: '运动' },
]

interface DemoSession {
  user: Session['user']
  csrfToken: string
  expiresAt: number
}

interface PersistedDevice {
  id: number
  gameId: number | null
  updatedAt: string
}

class HttpError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message)
  }
}

function seedDevices(): Device[] {
  const assignments = [638, 639, 640, 638, 641, 642, 643, null, 639, 640, 643, null]
  const locations = ['体验区 A', '体验区 A', '体验区 A', '体验区 A', '体验区 B', '体验区 B', '体验区 B', '体验区 B', 'VIP 区', 'VIP 区', '备用区', '备用区']
  return assignments.map((gameId, index) => ({
    id: index + 1,
    uuid: `b6dc4e1a-7a30-4f80-a642-${String(index + 1).padStart(12, '0')}`,
    name: `${locations[index]} · ${String(index + 1).padStart(2, '0')}`,
    ip: `192.168.1.${101 + index}`,
    status: index < 9 ? 'online' : 'offline',
    updatedAt: new Date(Date.now() - (index < 9 ? (index % 3 + 1) * 60_000 : (index - 8) * 35 * 60_000)).toISOString(),
    gameId,
  }))
}

function sendJson(response: ServerResponse, status: number, data: unknown): void {
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  })
  response.end(JSON.stringify(data))
}

function readSessionId(request: IncomingMessage): string | undefined {
  return request.headers.cookie?.split(';')
    .map((part) => part.trim())
    .find((part) => part.startsWith(`${COOKIE_NAME}=`))
    ?.slice(COOKIE_NAME.length + 1)
}

function setCookie(response: ServerResponse, value: string, maxAge: number): void {
  response.setHeader('Set-Cookie', `${COOKIE_NAME}=${value}; Path=/admin-api; HttpOnly; SameSite=Strict; Max-Age=${maxAge}`)
}

async function readJson(request: IncomingMessage): Promise<Record<string, unknown>> {
  const chunks: Buffer[] = []
  let size = 0
  for await (const chunk of request) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk)
    size += buffer.length
    if (size > 16_384) throw new HttpError(422, '请求内容过大')
    chunks.push(buffer)
  }
  let body: unknown
  try {
    body = JSON.parse(Buffer.concat(chunks).toString('utf8'))
  } catch {
    throw new HttpError(422, '请求格式错误，请提交 JSON 数据')
  }
  if (!body || typeof body !== 'object' || Array.isArray(body)) throw new HttpError(422, '请求格式错误')
  return body as Record<string, unknown>
}

function validPassword(password: string): boolean {
  const candidate = pbkdf2Sync(password, PASSWORD_SALT, 120_000, 32, 'sha256')
  return timingSafeEqual(candidate, PASSWORD_HASH)
}

function validSavedDevice(value: unknown, seed: Device[]): value is PersistedDevice {
  if (!value || typeof value !== 'object') return false
  const candidate = value as PersistedDevice
  return Number.isInteger(candidate.id)
    && seed.some((device) => device.id === candidate.id)
    && (candidate.gameId === null || games.some((game) => game.id === candidate.gameId))
    && typeof candidate.updatedAt === 'string'
    && Number.isFinite(Date.parse(candidate.updatedAt))
}

async function loadDevices(stateFile: string): Promise<Device[]> {
  const seed = seedDevices()
  let raw: string
  try {
    raw = await readFile(stateFile, 'utf8')
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') return seed
    throw new Error('读取演示设备配置失败，请检查 frontend/.local 的访问权限', { cause: error })
  }
  let state: { version?: number; devices?: unknown }
  try {
    state = JSON.parse(raw) as typeof state
  } catch (error) {
    throw new Error('演示设备配置文件不是有效的 JSON', { cause: error })
  }
  if (!state || state.version !== 1 || !Array.isArray(state.devices)
    || state.devices.length !== seed.length
    || !state.devices.every((device) => validSavedDevice(device, seed))
    || new Set(state.devices.map((device) => device.id)).size !== seed.length) {
    throw new Error('演示设备配置文件格式不正确，请检查 frontend/.local/demo-state.json')
  }
  const saved = new Map<number, PersistedDevice>(state.devices.map((device) => [device.id, device]))
  return seed.map((device) => ({ ...device, ...saved.get(device.id)! }))
}

async function saveDevices(stateFile: string, devices: Device[]): Promise<void> {
  await mkdir(dirname(stateFile), { recursive: true, mode: 0o700 })
  const temporaryFile = `${stateFile}.${randomBytes(8).toString('hex')}.tmp`
  const data = {
    version: 1,
    devices: devices.map(({ id, gameId, updatedAt }) => ({ id, gameId, updatedAt })),
  }
  await writeFile(temporaryFile, JSON.stringify(data, null, 2), { mode: 0o600 })
  await rename(temporaryFile, stateFile)
}

/** Local demonstration endpoints. Never loaded by the production build or API mode. */
export function demoApiPlugin(): Plugin {
  return {
    name: 'dirui-local-demo-api',
    apply: 'serve',
    async configureServer(server) {
      const stateFile = resolve(server.config.root, '.local/demo-state.json')
      let devices = await loadDevices(stateFile)
      let updateQueue: Promise<void> = Promise.resolve()
      const sessions = new Map<string, DemoSession>()

      function createSession(response: ServerResponse, user: Session['user'] = null): DemoSession {
        const id = randomBytes(32).toString('hex')
        const session = { user, csrfToken: randomBytes(24).toString('hex'), expiresAt: Date.now() + SESSION_TTL }
        sessions.set(id, session)
        setCookie(response, id, SESSION_TTL / 1_000)
        return session
      }

      function findSession(request: IncomingMessage): DemoSession | undefined {
        const now = Date.now()
        for (const [id, session] of sessions) if (session.expiresAt <= now) sessions.delete(id)
        const id = readSessionId(request)
        return id ? sessions.get(id) : undefined
      }

      function sessionData(session: DemoSession): Session {
        return { user: session.user, csrfToken: session.csrfToken, demo: true }
      }

      async function handle(request: IncomingMessage, response: ServerResponse): Promise<void> {
        const path = new URL(request.url ?? '/', 'http://localhost').pathname
        const method = request.method ?? 'GET'
        let session = findSession(request)

        if (method === 'GET' && path === '/session') {
          session ??= createSession(response)
          sendJson(response, 200, sessionData(session))
          return
        }

        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
          if (!session) throw new HttpError(401, '登录已过期，请重新登录')
          const token = request.headers['x-csrf-token']
          if (typeof token !== 'string' || token !== session.csrfToken) {
            throw new HttpError(403, '会话校验失败，请刷新页面后重试')
          }
        }

        if (method === 'POST' && path === '/login') {
          const body = await readJson(request)
          if (typeof body.username !== 'string' || typeof body.password !== 'string'
            || body.username.length > 100 || body.password.length > 200) {
            throw new HttpError(422, '请输入用户名和密码')
          }
          const passwordMatches = validPassword(body.password)
          if (body.username !== 'dirui' || !passwordMatches) throw new HttpError(401, '用户名或密码不正确')
          const oldId = readSessionId(request)
          if (oldId) sessions.delete(oldId)
          sendJson(response, 200, sessionData(createSession(response, { username: 'dirui' })))
          return
        }

        if (!session?.user) throw new HttpError(401, '登录已过期，请重新登录')

        if (method === 'POST' && path === '/logout') {
          const id = readSessionId(request)
          if (id) sessions.delete(id)
          setCookie(response, '', 0)
          sendJson(response, 200, { ok: true })
          return
        }

        if (method === 'GET' && path === '/devices') {
          sendJson(response, 200, devices)
          return
        }

        if (method === 'GET' && path === '/games') {
          sendJson(response, 200, games)
          return
        }

        const assignmentMatch = path.match(/^\/devices\/(\d+)\/game$/)
        if (method === 'PUT' && assignmentMatch) {
          const id = Number(assignmentMatch[1])
          const body = await readJson(request)
          if (!devices.some((device) => device.id === id)) throw new HttpError(404, '设备不存在')
          if (!Number.isInteger(body.gameId) || !games.some((game) => game.id === body.gameId)) {
            throw new HttpError(422, '请选择有效的游戏')
          }
          const gameId = body.gameId as number
          let updatedDevice: Device | undefined
          const update = updateQueue.then(async () => {
            const nextDevices = devices.map((device) => device.id === id
              ? { ...device, gameId, updatedAt: new Date().toISOString() }
              : device)
            try {
              await saveDevices(stateFile, nextDevices)
            } catch (error) {
              server.config.logger.error(`演示设备配置保存失败: ${error instanceof Error ? error.message : '未知错误'}`)
              throw new HttpError(500, '保存设备配置失败，请检查本地目录权限后重试')
            }
            devices = nextDevices
            updatedDevice = devices.find((device) => device.id === id)
          })
          updateQueue = update.then(() => undefined, () => undefined)
          await update
          sendJson(response, 200, updatedDevice)
          return
        }

        throw new HttpError(404, '接口不存在')
      }

      server.middlewares.use('/admin-api', (request, response) => {
        void handle(request, response).catch((error: unknown) => {
          if (response.headersSent) return
          sendJson(response, error instanceof HttpError ? error.status : 500, {
            message: error instanceof HttpError ? error.message : '演示服务暂时不可用，请稍后重试',
          })
        })
      })
    },
  }
}
