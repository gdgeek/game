import { nextTick, onBeforeUnmount, onMounted, watch, type Ref } from 'vue'

export function useModalFocus(dialog: Ref<HTMLElement | undefined>, close: () => void, busy?: () => boolean) {
  let previousFocus: HTMLElement | null = null
  let previousOverflow = ''
  function focusable() {
    return [...(dialog.value?.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), select:not(:disabled), a[href], [tabindex="0"]') || [])].filter(element => element.getClientRects().length)
  }
  function keydown(event: KeyboardEvent) {
    if (event.key === 'Escape') { event.preventDefault(); if (!busy?.()) close() }
    if (event.key !== 'Tab') return
    const elements = focusable(), first = elements[0], last = elements.at(-1)
    if (!first) { event.preventDefault(); dialog.value?.focus(); return }
    const outside = !dialog.value?.contains(document.activeElement) || document.activeElement === dialog.value
    if (event.shiftKey && (document.activeElement === first || outside)) { event.preventDefault(); last?.focus() }
    else if (!event.shiftKey && (document.activeElement === last || outside)) { event.preventDefault(); first.focus() }
  }
  onMounted(async () => {
    previousFocus = document.activeElement as HTMLElement
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', keydown)
    await nextTick()
    const initial = dialog.value?.querySelector<HTMLElement>('input:not(:disabled)') || focusable()[0] || dialog.value
    initial?.focus()
  })
  if (busy) watch(busy, async value => { if (value) { await nextTick(); dialog.value?.focus() } })
  onBeforeUnmount(() => {
    document.body.style.overflow = previousOverflow
    document.removeEventListener('keydown', keydown)
    void nextTick(() => previousFocus?.focus())
  })
}
