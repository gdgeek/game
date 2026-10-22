import { createRenderer } from 'vue'

export interface TestNode {
  tag: string
  props: Record<string, any>
  children: TestNode[]
  parent: TestNode | null
  text: string
  getAttribute: (name: string) => string | null
  getBoundingClientRect: () => { left: number; right: number; top: number; height: number }
}
export function testNode(tag: string): TestNode {
  return {
    tag, props: {}, children: [], parent: null, text: '',
    getAttribute(name) { return this.props[name] ?? null },
    getBoundingClientRect: () => ({ left: 100, right: 150, top: 100, height: 48 }),
  }
}
export function createTestRenderer(target?: TestNode) {
  function remove(node: TestNode) {
    const siblings = node.parent?.children
    if (siblings) siblings.splice(siblings.indexOf(node), 1)
    node.parent = null
  }
  return createRenderer<TestNode, TestNode>({
    createElement: testNode, createText: text => ({ ...testNode('#text'), text }), createComment: text => ({ ...testNode('#comment'), text }),
    insert(node, parent, anchor) {
      remove(node); node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node)
    },
    remove, patchProp: (node, name, _old, value) => { node.props[name] = value },
    setText: (node, text) => { node.text = text }, setElementText: (node, text) => { node.text = text; node.children = [] },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    querySelector: () => target ?? null,
  })
}
export function findNode(root: TestNode, predicate: (node: TestNode) => boolean): TestNode | undefined {
  if (predicate(root)) return root
  for (const child of root.children) { const found = findNode(child, predicate); if (found) return found }
}
