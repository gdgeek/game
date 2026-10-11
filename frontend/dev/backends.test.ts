import { describe, expect, it } from 'vitest'
import { readBackends } from './backends'

describe('development backend configuration', () => {
  it('adapts to a single backend without injecting a second one', () => {
    expect(readBackends({ APP_API_1_URL: 'https://only.example/' })).toEqual([
      { id: 'b0', name: '正式后端 1', host: 'only.example', origin: 'https://only.example' },
    ])
  })

  it('sorts arbitrary slots and keeps stable IDs across gaps', () => {
    expect(readBackends({
      APP_API_12_URL: 'https://last.example:8443',
      APP_API_3_URL: 'https://third.example:443',
      APP_API_1_URL: 'https://first.example',
      OTHER_SECRET: 'never expose this',
    })).toEqual([
      { id: 'b0', name: '正式后端 1', host: 'first.example', origin: 'https://first.example' },
      { id: 'b2', name: '正式后端 3', host: 'third.example', origin: 'https://third.example' },
      { id: 'b11', name: '正式后端 12', host: 'last.example:8443', origin: 'https://last.example:8443' },
    ])
  })

  it.each(['', 'http://host.example', 'https://user:pass@host.example', 'https://host.example/path', 'https://host.example?', 'https://host.example#', 'https://host.example//', 'https://host.example\\path', 'https://host.example;include', 'https://host.example:0', 'https://host.example:65536', 'https://host.example\n'])('rejects invalid configured origins %j', origin => {
    expect(() => readBackends({ APP_API_1_URL: origin })).toThrow()
  })

  it.each(['APP_API_0_URL', 'APP_API_01_URL', 'APP_API_9007199254740992_URL'])('rejects invalid slot keys %s', key => {
    expect(() => readBackends({ APP_API_1_URL: 'https://host.example', [key]: 'https://other.example' })).toThrow()
  })

  it('requires explicit configuration for real API development', () => {
    expect(() => readBackends({})).toThrow('APP_API_N_URL')
  })

  it('normalizes a non-default numeric port for the public manifest', () => {
    expect(readBackends({ APP_API_1_URL: 'https://host.example:00080' })[0]?.host).toBe('host.example:80')
  })

  it('rejects an invalid IPv4 URL before starting the proxy', () => {
    expect(() => readBackends({ APP_API_1_URL: 'https://999.999.999.999' })).toThrow()
  })
})
