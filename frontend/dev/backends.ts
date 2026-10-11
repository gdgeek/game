import type { BackendOption } from '../src/types'

export interface ConfiguredBackend extends BackendOption { origin: string }

// Only the development server reads these variables. The browser receives the
// same public manifest that the production Nginx entrypoint generates.
export function readBackends(env: Record<string, string>): ConfiguredBackend[] {
  const slots = Object.keys(env).filter(key => /^APP_API_[0-9]+_URL$/.test(key)).map(key => {
    const rawSlot = key.slice(8, -4)
    const slot = Number(rawSlot)
    if (!/^[1-9][0-9]*$/.test(rawSlot) || !Number.isSafeInteger(slot)) throw new Error(`Invalid backend slot: ${key}`)
    return { key, slot }
  }).sort((a, b) => a.slot - b.slot)
  if (!slots.length) throw new Error('Configure at least one APP_API_N_URL in .env.local before starting the real API server')

  return slots.map(({ key, slot }) => {
    const value = env[key]!
    const match = /^https:\/\/([a-zA-Z0-9.-]+)(?::([0-9]{1,5}))?\/?$/.exec(value)
    const hostname = match?.[1] ?? ''
    const port = match?.[2] === undefined ? 443 : Number(match[2])
    const validLabels = hostname.split('.').every(label => label.length <= 63 && /^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/.test(label))
    if (!match || /\s/.test(value) || hostname.length > 253 || !validLabels || !Number.isInteger(port) || port < 1 || port > 65535) {
      throw new Error(`Invalid HTTPS origin for ${key}`)
    }
    // Node's proxy also requires a semantically valid URL (including IPs).
    try { new URL(value) } catch { throw new Error(`Invalid HTTPS origin for ${key}`) }
    const host = `${hostname}${port === 443 ? '' : `:${port}`}`
    return { id: `b${slot - 1}`, name: `正式后端 ${slot}`, host, origin: `https://${host}` }
  })
}
