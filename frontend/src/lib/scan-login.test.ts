import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createScanLoginController, type ScanLoginApi, type ScanLoginState } from './scan-login'
import type { ScanLoginTicket, ScanLoginTicketStatus, Session } from '../types'

const ticket = (id = 'a'.repeat(32), expiresAt = '2026-10-11T12:05:00Z'): ScanLoginTicket => ({ ticketId: id, qrText: `qr-${id}`, expiresAt })
const pending: ScanLoginTicketStatus = { status: 'pending', expiresAt: ticket().expiresAt }
const approved: ScanLoginTicketStatus = { ...pending, status: 'approved' }
const session: Session = { user: { username: '管理员' }, csrfToken: 'rotated' }
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function setup() {
  const states: ScanLoginState[] = []
  const api = {
    create: vi.fn<ScanLoginApi['create']>().mockResolvedValue(ticket()),
    get: vi.fn<ScanLoginApi['get']>().mockResolvedValue(pending),
    consume: vi.fn<ScanLoginApi['consume']>().mockResolvedValue(session),
    cancel: vi.fn<ScanLoginApi['cancel']>().mockResolvedValue(undefined),
    session: vi.fn<ScanLoginApi['session']>().mockResolvedValue({ user: null, csrfToken: 'anonymous' }),
  }
  const onSession = vi.fn()
  const onConsumeBusy = vi.fn()
  const controller = createScanLoginController({ api, onState: state => states.push(state), onSession, onConsumeBusy })
  return { api, states, onSession, onConsumeBusy, controller }
}

describe('scan login lifecycle', () => {
  beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime('2026-10-11T12:00:00Z') })
  afterEach(() => vi.useRealTimers())

  it('polls every two seconds with at most one status request in flight', async () => {
    const { api, states, controller } = setup()
    const result = deferred<ScanLoginTicketStatus>()
    api.get.mockReturnValueOnce(result.promise)
    await controller.refresh()
    expect(states.at(-1)?.phase).toBe('pending')
    await vi.advanceTimersByTimeAsync(2000)
    expect(api.get).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.get).toHaveBeenCalledTimes(1)
    result.resolve(pending)
    await vi.advanceTimersByTimeAsync(1999)
    expect(api.get).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(api.get).toHaveBeenCalledTimes(2)
    controller.dispose()
  })

  it('cancels the old ticket and ignores a late approved poll after refresh', async () => {
    const { api, controller, onSession } = setup()
    const result = deferred<ScanLoginTicketStatus>()
    api.get.mockReturnValueOnce(result.promise)
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    const next = ticket('c'.repeat(32))
    api.create.mockResolvedValueOnce(next)
    await controller.refresh()
    expect(api.cancel).toHaveBeenCalledWith(ticket().ticketId)
    result.resolve(approved)
    await vi.advanceTimersByTimeAsync(0)
    expect(api.consume).not.toHaveBeenCalled()
    expect(onSession).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(2000)
    expect(api.get).toHaveBeenLastCalledWith(next.ticketId)
    controller.dispose()
  })

  it('cancels a ticket that finishes creating after its generation was replaced', async () => {
    const { api, states, controller } = setup()
    const creating = deferred<ScanLoginTicket>()
    api.create.mockReturnValueOnce(creating.promise)
    const first = controller.refresh()
    const next = ticket('c'.repeat(32))
    api.create.mockResolvedValueOnce(next)
    await controller.refresh()
    creating.resolve(ticket())
    await first
    expect(api.cancel).toHaveBeenCalledWith(ticket().ticketId)
    expect(states.at(-1)?.ticket?.ticketId).toBe(next.ticketId)
    controller.dispose()
  })

  it('stops and cancels on unmount without consuming a late approved response', async () => {
    const { api, onSession, controller } = setup()
    const result = deferred<ScanLoginTicketStatus>()
    api.get.mockReturnValueOnce(result.promise)
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    controller.dispose()
    result.resolve(approved)
    await vi.advanceTimersByTimeAsync(20000)
    expect(api.cancel).toHaveBeenCalledWith(ticket().ticketId)
    expect(api.get).toHaveBeenCalledTimes(1)
    expect(api.consume).not.toHaveBeenCalled()
    expect(onSession).not.toHaveBeenCalled()
  })

  it('explicitly consumes approval and holds the busy lock until the Session response settles', async () => {
    const { api, states, onSession, onConsumeBusy, controller } = setup()
    const consuming = deferred<Session>()
    api.get.mockResolvedValueOnce(approved)
    api.consume.mockReturnValueOnce(consuming.promise)
    onSession.mockImplementation(() => expect(onConsumeBusy).toHaveBeenLastCalledWith(true))
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    expect(api.consume).toHaveBeenCalledExactlyOnceWith(ticket().ticketId)
    expect(states.at(-1)?.phase).toBe('consuming')
    expect(onConsumeBusy).toHaveBeenLastCalledWith(true)
    await controller.refresh()
    expect(api.create).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(6000)
    expect(api.get).toHaveBeenCalledTimes(1)
    consuming.resolve(session)
    await vi.advanceTimersByTimeAsync(0)
    expect(onSession).toHaveBeenCalledExactlyOnceWith(session)
    expect(onConsumeBusy.mock.calls).toEqual([[true], [false]])
    expect(states.at(-1)?.phase).toBe('consumed')
    controller.dispose()
    expect(api.cancel).not.toHaveBeenCalled()
  })

  it('keeps the cookie-changing consume lock even if disposed, then ignores its Session', async () => {
    const { api, onSession, onConsumeBusy, controller } = setup()
    const consuming = deferred<Session>()
    api.get.mockResolvedValueOnce(approved)
    api.consume.mockReturnValueOnce(consuming.promise)
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    controller.dispose()
    expect(onConsumeBusy.mock.calls).toEqual([[true]])
    consuming.resolve(session)
    await vi.advanceTimersByTimeAsync(0)
    expect(onConsumeBusy.mock.calls).toEqual([[true], [false]])
    expect(onSession).not.toHaveBeenCalled()
  })

  it('expires at the boundary without another status request', async () => {
    const { api, states, controller } = setup()
    api.create.mockResolvedValueOnce(ticket(undefined, '2026-10-11T12:00:01Z'))
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(1000)
    expect(states.at(-1)?.phase).toBe('expired')
    expect(api.get).not.toHaveBeenCalled()
    expect(api.consume).not.toHaveBeenCalled()
    controller.dispose()
  })

  it('does not consume approval that arrived after expiry', async () => {
    const { api, states, controller } = setup()
    const result = deferred<ScanLoginTicketStatus>()
    api.get.mockReturnValueOnce(result.promise)
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(300000)
    result.resolve(approved)
    await vi.advanceTimersByTimeAsync(0)
    expect(states.at(-1)?.phase).toBe('expired')
    expect(api.consume).not.toHaveBeenCalled()
    controller.dispose()
  })

  it.each(['expired', 'cancelled', 'consumed'] as const)('stops polling a %s ticket', async status => {
    const { api, states, controller } = setup()
    api.get.mockResolvedValueOnce({ ...pending, status })
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(10000)
    expect(states.at(-1)?.phase).toBe(status)
    expect(api.get).toHaveBeenCalledTimes(1)
    expect(api.consume).not.toHaveBeenCalled()
    controller.dispose()
  })

  it('surfaces errors and releases the busy lock so a fresh ticket can be requested', async () => {
    const { api, states, onConsumeBusy, controller } = setup()
    api.get.mockResolvedValueOnce(approved)
    api.consume.mockRejectedValueOnce(new Error('管理员权限已撤销'))
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(10000)
    expect(states.at(-1)).toMatchObject({ phase: 'error', error: '管理员权限已撤销' })
    expect(onConsumeBusy.mock.calls).toEqual([[true], [false]])
    expect(api.get).toHaveBeenCalledTimes(1)
    await controller.refresh()
    expect(states.at(-1)?.phase).toBe('pending')
    controller.dispose()
  })

  it('recovers a committed Session after an ambiguous consume failure while keeping the lock', async () => {
    const { api, states, onSession, onConsumeBusy, controller } = setup()
    const recovering = deferred<Session>()
    api.get.mockResolvedValueOnce(approved)
    api.consume.mockRejectedValueOnce(new Error('请求超时'))
    api.session.mockReturnValueOnce(recovering.promise)
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    expect(states.at(-1)?.phase).toBe('reconciling')
    expect(onConsumeBusy.mock.calls).toEqual([[true]])
    await controller.refresh()
    expect(api.create).toHaveBeenCalledTimes(1)
    recovering.resolve(session)
    await vi.advanceTimersByTimeAsync(0)
    expect(onSession).toHaveBeenCalledExactlyOnceWith(session)
    expect(onConsumeBusy.mock.calls).toEqual([[true], [false]])
    controller.dispose()
  })

  it('keeps authentication locked after failed reconciliation until an explicit retry settles it', async () => {
    const { api, states, onConsumeBusy, controller } = setup()
    api.get.mockResolvedValueOnce(approved)
    api.consume.mockRejectedValueOnce(new Error('请求超时'))
    api.session.mockRejectedValueOnce(new Error('离线'))
    await controller.refresh()
    await vi.advanceTimersByTimeAsync(2000)
    expect(states.at(-1)).toMatchObject({ phase: 'reconciling' })
    expect(states.at(-1)?.error).not.toBe('')
    expect(onConsumeBusy.mock.calls).toEqual([[true]])
    await controller.refresh()
    expect(api.create).toHaveBeenCalledTimes(1)
    await controller.retrySession()
    expect(states.at(-1)).toMatchObject({ phase: 'error', error: '请求超时' })
    expect(onConsumeBusy.mock.calls).toEqual([[true], [false]])
    controller.dispose()
  })
})
