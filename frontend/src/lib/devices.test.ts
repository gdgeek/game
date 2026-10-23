import { describe, expect, it } from 'vitest'
import type { Device } from '../types'
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

  it('keeps the original order for default sorting without returning the source array', () => {
    const source = [device(10), device(2), device(1)]
    for (const direction of ['asc', 'desc'] as const) {
      const sorted = sortDevices(source, 'default', direction)
      expect(ids(sorted)).toEqual([10, 2, 1])
      expect(sorted).not.toBe(source)
    }
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
    const source = [device(3, { version: 2, name: 'VR 2', updatedAt: '2026-10-23T08:00:00+08:00' }), device(1, { version: 2, name: 'vr 2', updatedAt: '2026-10-23T00:00:00Z' })]
    for (const key of ['version', 'name', 'updatedAt'] as const) {
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
