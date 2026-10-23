export type DeviceStatus = 'online' | 'offline' | 'unknown'

export interface Device {
  id: number
  uuid: string
  version?: number
  name: string
  ip: string
  status: DeviceStatus
  updatedAt: string
  gameId: number | null
  title?: string
  slogans?: string[]
  pictures?: string[]
  thumbs?: string[]
}

export interface DeviceConfiguration {
  gameId: number | null
  title: string
  slogans: string[]
  pictures: string[]
  thumbs: string[]
}

export type DeviceConfigurationPatch = Partial<Omit<DeviceConfiguration, 'gameId'>> & { gameId?: number }

export interface DeviceImagePreview {
  source: string
  url: string
  expiresAt: number
}

export interface Game {
  id: number
  name: string
  description: string
  category: string
}

export interface Session {
  user: { username: string; role?: 'admin' | 'device_manager' } | null
  csrfToken?: string
  token?: { accessToken: string; refreshToken: string; expires: string; refreshExpires: string }
  authMode?: 'jwt' | 'session'
  demo?: boolean
  scanLoginEnabled?: boolean
}

export interface ScanLoginTicket {
  ticketId: string
  qrText: string
  expiresAt: string
}

export type ScanLoginStatus = 'pending' | 'approved' | 'consumed' | 'cancelled' | 'expired'

export interface ScanLoginTicketStatus {
  status: ScanLoginStatus
  expiresAt: string
}

export type DeviceFilterStatus = 'all' | DeviceStatus | 'configured' | 'unconfigured'
export type DeviceSortKey = 'default' | 'version' | 'name' | 'updatedAt' | 'id'
export type DeviceSortDirection = 'asc' | 'desc'

export interface DeviceFilters {
  search: string
  status: DeviceFilterStatus
  gameId: number | null
}

export interface DeviceSummary {
  total: number
  configured: number
  unconfigured: number
  online: number
  offline: number
  unknown: number
}
