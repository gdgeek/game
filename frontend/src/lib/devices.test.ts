import { describe, expect, it } from 'vitest'
import type { Device, Game } from '../types'
import { filterDevices, sortDevices, summarizeDevices } from './devices'

const devices: Device[] = [
  { id: 1, uuid: 'VR-A01', name: '一号大厅', ip: '192.168.1.101', status: 'online', updatedAt: '', gameId: 638 },
  { id: 2, uuid: 'VR-B02', name: '二号大厅', ip: '192.168.1.102', status: 'offline', updatedAt: '', gameId: null },
  { id: 3, uuid: 'VR-C03', name: '体验区', ip: '192.168.1.103', status: 'unknown', updatedAt: '', gameId: 639 },
]

describe('filterDevices', () => {
  it('searches names, IP addresses, and UUIDs without case or surrounding whitespace affecting the result', () => {
    expect(filterDevices(devices, { search: '一号', status: 'all', gameId: null })).toEqual([devices[0]])
    expect(filterDevices(devices, { search: '  vr-b02 ', status: 'all', gameId: null })).toEqual([devices[1]])
    expect(filterDevices(devices, { search: '.103', status: 'all', gameId: null })).toEqual([devices[2]])
  })

  it('combines connection, game assignment, and text filters', () => {
    expect(filterDevices(devices, { search: '大厅', status: 'online', gameId: 638 })).toEqual([devices[0]])
    expect(filterDevices(devices, { search: '', status: 'configured', gameId: 639 })).toEqual([devices[2]])
    expect(filterDevices(devices, { search: '', status: 'unconfigured', gameId: null })).toEqual([devices[1]])
    expect(filterDevices(devices, { search: '', status: 'unknown', gameId: null })).toEqual([devices[2]])
    expect(filterDevices(devices, { search: '', status: 'offline', gameId: 638 })).toEqual([])
  })

  it('does not mutate the source array', () => {
    const original = [...devices]
    filterDevices(devices, { search: '', status: 'all', gameId: null })
    expect(devices).toEqual(original)
  })
})

describe('summarizeDevices', () => {
  it('counts configuration and every known connection state independently', () => {
    expect(summarizeDevices(devices)).toEqual({ total: 3, configured: 2, unconfigured: 1, online: 1, offline: 1, unknown: 1 })
  })

  it('returns zero counts for an empty fleet', () => {
    expect(summarizeDevices([])).toEqual({ total: 0, configured: 0, unconfigured: 0, online: 0, offline: 0, unknown: 0 })
  })
})

describe('sortDevices', () => {
  const device = (id: number, fields: Partial<Device> = {}): Device => ({ ...devices[0]!, id, ...fields })
  const ids = (items: Device[]) => items.map(item => item.id)

  it('orders creation times independently of device IDs and update times and keeps missing or invalid times last', () => {
    const source = [
      device(8, { createdAt: '2026-10-21 12:00:00', updatedAt: '2026-10-23 12:00:00' }),
      device(2, { createdAt: '2026-10-23 12:00:00', updatedAt: '2026-10-21 12:00:00' }),
      device(9, { createdAt: '2026-10-22 12:00:00', updatedAt: '2026-10-22 12:00:00' }),
      device(1, { updatedAt: '2026-10-24 12:00:00' }),
      device(3, { createdAt: '' }),
      device(4, { createdAt: 'invalid-date' }),
    ]
    expect(ids(sortDevices(source, 'createdAt', 'asc'))).toEqual([8, 9, 2, 1, 3, 4])
    expect(ids(sortDevices(source, 'createdAt', 'desc'))).toEqual([2, 9, 8, 1, 3, 4])
  })

  it('compares creation timestamps by instant when their time zones differ', () => {
    const source = [device(1, { createdAt: '2026-10-23T08:30:00+08:00' }), device(2, { createdAt: '2026-10-23T01:00:00Z' }), device(3, { createdAt: '2026-10-23T00:00:00Z' })]
    expect(ids(sortDevices(source, 'createdAt', 'asc'))).toEqual([3, 1, 2])
    expect(ids(sortDevices(source, 'createdAt', 'desc'))).toEqual([2, 1, 3])
  })

  it('orders statuses online, offline and unknown in ascending order and reverses that order for descending', () => {
    const source = [device(1, { status: 'offline' }), device(2, { status: 'online' }), device(3, { status: 'unknown' })]
    expect(ids(sortDevices(source, 'status', 'asc'))).toEqual([2, 1, 3])
    expect(ids(sortDevices(source, 'status', 'desc'))).toEqual([3, 1, 2])
  })

  it('orders current games by their displayed catalog names and keeps unconfigured devices last', () => {
    const games: Game[] = [
      { id: 100, name: '游戏 10', description: '', category: '' },
      { id: 2, name: '游戏 2', description: '', category: '' },
      { id: 3, name: '游戏 1', description: '', category: '' },
    ]
    const source = [device(1, { gameId: 100 }), device(2, { gameId: 2 }), device(3, { gameId: 3 }), device(4, { gameId: null }), device(5, { gameId: null })]
    expect(ids(sortDevices(source, 'game', 'asc', games))).toEqual([3, 2, 1, 4, 5])
    expect(ids(sortDevices(source, 'game', 'desc', games))).toEqual([1, 2, 3, 4, 5])
  })

  it('uses the displayed scene fallback when a game is absent from the catalog or no catalog is provided', () => {
    const games: Game[] = [{ id: 100, name: '场景 5', description: '', category: '' }]
    const source = [device(1, { gameId: 10 }), device(2, { gameId: 2 }), device(3, { gameId: 100 }), device(4, { gameId: null })]
    expect(ids(sortDevices(source, 'game', 'asc', games))).toEqual([2, 3, 1, 4])
    expect(ids(sortDevices(source, 'game', 'desc', games))).toEqual([1, 3, 2, 4])
    expect(ids(sortDevices(source, 'game', 'asc'))).toEqual([2, 1, 3, 4])
    expect(ids(sortDevices(source, 'game', 'desc'))).toEqual([3, 1, 2, 4])
  })

  it('orders IP addresses naturally by their numeric parts and keeps blank addresses last in either direction', () => {
    const source = [device(1, { ip: '192.168.1.10' }), device(2, { ip: '' }), device(3, { ip: '192.168.1.2' }), device(4, { ip: '10.0.0.1' }), device(5, { ip: '   ' })]
    expect(ids(sortDevices(source, 'ip', 'asc'))).toEqual([4, 3, 1, 2, 5])
    expect(ids(sortDevices(source, 'ip', 'desc'))).toEqual([1, 3, 4, 2, 5])
  })

  it('orders numeric versions including zero and keeps unknown versions last in either direction', () => {
    const source = [device(1), device(2, { version: 10 }), device(3, { version: 0 }), device(4, { version: 2 }), device(5, { version: 2147483647 }), device(6)]
    expect(ids(sortDevices(source, 'version', 'asc'))).toEqual([3, 4, 2, 5, 1, 6])
    expect(ids(sortDevices(source, 'version', 'desc'))).toEqual([5, 2, 4, 3, 1, 6])
  })

  it('orders Chinese device names using natural numeric order', () => {
    const source = [device(1, { name: '中区 2' }), device(2, { name: '阿区 10' }), device(3, { name: '波区' }), device(4, { name: '阿区 2' })]
    expect(ids(sortDevices(source, 'name', 'asc'))).toEqual([4, 2, 3, 1])
    expect(ids(sortDevices(source, 'name', 'desc'))).toEqual([1, 3, 2, 4])
  })

  it('sorts an empty device name using the displayed device ID fallback', () => {
    const source = [device(10, { name: '' }), device(3, { name: '设备 #3' }), device(2, { name: '' })]
    expect(ids(sortDevices(source, 'name', 'asc'))).toEqual([2, 3, 10])
  })

  it('sorts database timestamps by date and keeps missing or invalid times last in either direction', () => {
    const source = [device(1, { updatedAt: '' }), device(2, { updatedAt: '2026-10-23 11:00:00' }), device(3, { updatedAt: 'invalid-date' }), device(4, { updatedAt: '2026-10-22 23:00:00' })]
    expect(ids(sortDevices(source, 'updatedAt', 'asc'))).toEqual([4, 2, 1, 3])
    expect(ids(sortDevices(source, 'updatedAt', 'desc'))).toEqual([2, 4, 1, 3])
  })

  it('compares ISO timestamps by instant instead of lexicographic order', () => {
    const source = [device(1, { updatedAt: '2026-10-23T08:30:00+08:00' }), device(2, { updatedAt: '2026-10-23T01:00:00Z' }), device(3, { updatedAt: '2026-10-23T00:00:00Z' })]
    expect(ids(sortDevices(source, 'updatedAt', 'asc'))).toEqual([3, 1, 2])
    expect(ids(sortDevices(source, 'updatedAt', 'desc'))).toEqual([2, 1, 3])
  })

  it.each(['asc', 'desc'] as const)('preserves source order for equal values when sorting %s', direction => {
    const source = [device(3, { version: 2, name: 'VR 2', createdAt: '2026-10-23T08:00:00+08:00', updatedAt: '2026-10-23T08:00:00+08:00' }), device(1, { version: 2, name: 'vr 2', createdAt: '2026-10-23T00:00:00Z', updatedAt: '2026-10-23T00:00:00Z' })]
    for (const key of ['version', 'name', 'status', 'game', 'ip', 'createdAt', 'updatedAt'] as const) {
      expect(ids(sortDevices(source, key, direction))).toEqual([3, 1])
    }
  })

  it('orders IDs numerically and leaves the source array and its objects unchanged', () => {
    const source = Object.freeze([Object.freeze(device(10)), Object.freeze(device(2)), Object.freeze(device(1))])
    const original = [...source]
    expect(ids(sortDevices(source, 'id', 'asc'))).toEqual([1, 2, 10])
    expect(ids(sortDevices(source, 'id', 'desc'))).toEqual([10, 2, 1])
    expect(source).toEqual(original)
    expect(sortDevices(source, 'id', 'asc')[0]).toBe(source[2])
  })

  it('sorts the whole filtered result before pagination', () => {
    const source = [device(1, { version: 10, gameId: 638 }), device(2, { version: 0, gameId: 639 }), device(3, { version: 2, gameId: 638 }), device(4, { version: 1, gameId: 638 })]
    const filtered = filterDevices(source, { search: '', status: 'all', gameId: 638 })
    const sorted = sortDevices(filtered, 'version', 'asc')
    expect(ids(sorted.slice(0, 2))).toEqual([4, 3])
    expect(ids(sorted.slice(2, 4))).toEqual([1])
  })
})
