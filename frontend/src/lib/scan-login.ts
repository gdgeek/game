import type { ScanLoginTicket, ScanLoginTicketStatus, Session } from '../types'

export interface ScanLoginApi {
  create(): Promise<ScanLoginTicket>
  get(ticketId: string): Promise<ScanLoginTicketStatus>
  consume(ticketId: string): Promise<Session>
  cancel(ticketId: string): Promise<unknown>
}
export interface ScanLoginState {
  phase: 'idle' | 'creating' | 'pending' | 'consuming' | 'expired' | 'cancelled' | 'consumed' | 'error'
  ticket: ScanLoginTicket | null
  error: string
}
interface ScanLoginOptions {
  api: ScanLoginApi
  onState(state: ScanLoginState): void
  onSession(session: Session): void
  onConsumeBusy(busy: boolean): void
}

export function createScanLoginController(options: ScanLoginOptions) {
  let state: ScanLoginState = { phase: 'idle', ticket: null, error: '' }
  let generation = 0
  let disposed = false
  let consuming = false
  let timer: ReturnType<typeof setTimeout> | undefined
  const active = (version: number) => !disposed && generation === version

  function update(phase: ScanLoginState['phase'], ticket = state.ticket, error = '') {
    state = { phase, ticket, error }
    options.onState(state)
  }
  function cancel(ticketId: string) {
    // Cleanup is best effort; the server also invalidates older tickets on create.
    void options.api.cancel(ticketId).catch(() => {})
  }
  function retire() {
    generation++
    clearTimeout(timer)
    if (state.ticket && !consuming && state.phase !== 'consumed') cancel(state.ticket.ticketId)
  }
  function expired(ticket: ScanLoginTicket) { return Date.parse(ticket.expiresAt) <= Date.now() }
  function finishSession(session: Session, version: number) {
    try {
      if (active(version)) {
        if (session.user) {
          update('consumed')
          options.onSession(session)
        } else update('error', state.ticket, '登录未成功，请刷新二维码后重试。')
      }
    } finally {
      consuming = false
      options.onConsumeBusy(false)
    }
  }
  async function consume(ticket: ScanLoginTicket, version: number) {
    consuming = true
    options.onConsumeBusy(true)
    update('consuming')
    try {
      finishSession(await options.api.consume(ticket.ticketId), version)
    } catch (error) {
      const confirmedFailure = error instanceof Error && 'status' in error && typeof error.status === 'number' && error.status >= 400
      if (active(version)) update('error', ticket, confirmedFailure ? error.message : '登录结果无法确认，请刷新二维码后重新扫码。')
      consuming = false
      options.onConsumeBusy(false)
    }
  }
  function schedule(ticket: ScanLoginTicket, version: number) {
    timer = setTimeout(() => { void poll(ticket, version) }, Math.min(2000, Math.max(0, Date.parse(ticket.expiresAt) - Date.now())))
  }
  async function poll(ticket: ScanLoginTicket, version: number) {
    if (!active(version)) return
    if (expired(ticket)) { update('expired'); return }
    try {
      const result = await options.api.get(ticket.ticketId)
      if (!active(version)) return
      if (expired(ticket) || Date.parse(result.expiresAt) <= Date.now()) { update('expired'); return }
      if (result.status === 'approved') { await consume(ticket, version); return }
      if (result.status !== 'pending') { update(result.status); return }
      schedule(ticket, version)
    } catch (error) {
      if (active(version)) update('error', ticket, error instanceof Error ? error.message : '无法获取扫码状态，请重试。')
    }
  }
  async function refresh() {
    if (disposed || consuming) return
    retire()
    const version = generation
    update('creating', null)
    try {
      const ticket = await options.api.create()
      if (!active(version)) { cancel(ticket.ticketId); return }
      if (expired(ticket)) { update('expired', ticket); return }
      update('pending', ticket)
      schedule(ticket, version)
    } catch (error) {
      if (active(version)) update('error', null, error instanceof Error ? error.message : '无法生成二维码，请重试。')
    }
  }
  function dispose() {
    if (disposed) return
    retire()
    disposed = true
    // A completed consume response is required before this tab accepts a login.
  }
  return { refresh, dispose }
}
