import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h, nextTick, ref, type Component } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { parse, compileScript } from 'vue/compiler-sfc'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'
import ts from 'typescript'
import ConfigurationList from '../components/ConfigurationList.vue'
import { createTestRenderer, findNode, testNode } from './test-renderer'

const source = 'https://bucket.cos.ap-test.myqcloud.com/image.png'
const signed = `${source}?q-signature=temporary-secret`

describe('configuration image presentation', () => {
  it('renders the resolved preview URL while leaving the editable original address intact', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(ConfigurationList, {
      modelValue: [source], field: 'pictures', title: '图片', description: '', disabled: false, images: true,
      resolvedUrls: { [source]: signed },
    }) }))
    expect(html).toContain(`src="${signed}"`)
    expect(html).toContain(`value="${source}"`)
    expect(html).not.toContain(`value="${signed}"`)
  })
})

describe('configuration image interaction', () => {
  let clientComponent: Component
  let stop: (() => void) | undefined
  let documentEvents: EventTarget
  let windowEvents: EventTarget
  beforeAll(async () => {
    // Vitest loads .vue files as SSR modules; compile the same SFC for client lifecycle interactions.
    const filename = new URL('../components/ConfigurationList.vue', import.meta.url)
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename: filename.pathname })
    const script = compileScript(descriptor, { id: 'configuration-preview-test', inlineTemplate: true })
    const require = createRequire(import.meta.url)
    const javascript = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
      .replace(/from (["'])([^"']+)\1/g, (_match, _quote, name: string) => `from '${pathToFileURL(require.resolve(name)).href}'`)
    const moduleUrl = `data:text/javascript;base64,${Buffer.from(javascript).toString('base64')}`
    clientComponent = (await import(/* @vite-ignore */ moduleUrl)).default
  })
  beforeEach(() => {
    documentEvents = new EventTarget(); windowEvents = new EventTarget()
    vi.stubGlobal('document', documentEvents)
    vi.stubGlobal('window', Object.assign(windowEvents, { innerWidth: 640, innerHeight: 480 }))
  })
  afterEach(() => { stop?.(); vi.unstubAllGlobals() })
  function mount() {
    const urls = ref<Record<string, string>>({})
    const root = testNode('root'), body = testNode('body')
    const app = createTestRenderer(body).createApp({ render: () => h(clientComponent, {
      modelValue: [source], field: 'pictures', title: '图片', description: '', disabled: false, images: true, resolvedUrls: urls.value,
    }) })
    app.mount(root); stop = () => app.unmount()
    return { root, body, urls }
  }

  it('recovers from an unsigned load error when the signature arrives, and closes a stale large preview on renewal', async () => {
    const { root, body, urls } = mount()
    const failed = findNode(root, node => node.tag === 'img')!
    failed.props.onError({ target: failed }); await nextTick()
    expect(findNode(root, node => node.tag === 'img')).toBeUndefined()
    urls.value = { [source]: signed }; await nextTick()
    expect(findNode(root, node => node.tag === 'img')?.props.src).toBe(signed)
    const button = findNode(root, node => node.props.class === 'configuration-preview')!
    button.props.onMouseenter({ currentTarget: button }); await nextTick()
    expect(findNode(body, node => node.tag === 'img')?.props.src).toBe(signed)
    urls.value = { [source]: `${source}?sign=renewed` }; await nextTick()
    expect(findNode(body, node => node.tag === 'img')).toBeUndefined()
    expect(findNode(root, node => node.tag === 'img')?.props.src).toBe(`${source}?sign=renewed`)
    expect(findNode(root, node => node.tag === 'input')?.props.value).toBe(source)
  })

  it('keeps keyboard previews inside the viewport and closes them on Escape, scrolling and resizing', async () => {
    const { root, body } = mount()
    const button = findNode(root, node => node.props.class === 'configuration-preview')!
    button.props.onFocus({ currentTarget: button }); await nextTick()
    const popover = findNode(body, node => node.props.class === 'configuration-image-popover')!
    expect(parseFloat(popover.props.style.left)).toBeGreaterThanOrEqual(12)
    expect(parseFloat(popover.props.style.left) + parseFloat(popover.props.style.width)).toBeLessThanOrEqual(628)
    const escape = new Event('keydown', { cancelable: true }); Object.defineProperty(escape, 'key', { value: 'Escape' })
    documentEvents.dispatchEvent(escape); await nextTick()
    expect(escape.defaultPrevented).toBe(true)
    expect(findNode(body, node => node.tag === 'img')).toBeUndefined()
    for (const type of ['scroll', 'resize']) {
      button.props.onFocus({ currentTarget: button }); await nextTick()
      expect(findNode(body, node => node.tag === 'img')).toBeDefined()
      windowEvents.dispatchEvent(new Event(type)); await nextTick()
      expect(findNode(body, node => node.tag === 'img')).toBeUndefined()
    }
  })
})
