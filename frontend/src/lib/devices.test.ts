import { describe, expect, it } from 'vitest'
import type { Device } from '../types'
import { filterDevices, summarizeDevices } from './devices'

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
