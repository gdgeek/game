<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Image as ImageIcon, Plus, Trash2 } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: string[]
  title: string
  field: string
  description: string
  disabled: boolean
  images?: boolean
  resolvedUrls?: Readonly<Record<string, string>>
}>()
const emit = defineEmits<{ 'update:modelValue': [items: string[]] }>()
const failedImages = ref(new Set<string>())
const preview = ref<{ item: string; index: number; src: string; left: number; top: number; width: number; height: number } | null>(null)
function closePreview() { preview.value = null }
function openPreview(item: string, index: number, event: Event) {
  const src = previewUrl(item)
  if (!src) return
  const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const width = Math.min(440, window.innerWidth - 24)
  const height = Math.min(360, window.innerHeight - 24)
  const preferredLeft = bounds.right + 12 + width <= window.innerWidth - 12 ? bounds.right + 12 : bounds.left - width - 12
  preview.value = {
    item, index, src, width, height,
    left: Math.max(12, Math.min(preferredLeft, window.innerWidth - width - 12)),
    top: Math.max(12, Math.min(bounds.top + bounds.height / 2 - height / 2, window.innerHeight - height - 12)),
  }
}
function imageFailed(item: string, event: Event) {
  const src = (event.target as HTMLImageElement).getAttribute('src')
  if (src) failedImages.value.add(src)
  if (preview.value?.item === item) closePreview()
}
function previewKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape' || !preview.value) return
  event.preventDefault()
  event.stopPropagation()
  closePreview()
}
watch(() => props.modelValue, closePreview)
watch(() => props.resolvedUrls, () => {
  failedImages.value.clear()
  closePreview()
}, { deep: true, flush: 'sync' })
onMounted(() => {
  document.addEventListener('keydown', previewKeydown, true)
  window.addEventListener('scroll', closePreview, true)
  window.addEventListener('resize', closePreview)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', previewKeydown, true)
  window.removeEventListener('scroll', closePreview, true)
  window.removeEventListener('resize', closePreview)
})
function setItem(index: number, event: Event) {
  const items = [...props.modelValue]
  items[index] = (event.target as HTMLInputElement).value
  emit('update:modelValue', items)
}
function previewUrl(value: string) {
  try {
    const url = new URL(props.resolvedUrls?.[value.trim()] ?? value.trim())
    return ['http:', 'https:'].includes(url.protocol) && !failedImages.value.has(url.href) ? url.href : ''
  } catch { return '' }
}
</script>

<template>
  <section class="configuration-list" :aria-labelledby="`${field}-title`">
    <div class="configuration-heading">
      <div><h3 :id="`${field}-title`">{{ title }}</h3><p :id="`${field}-description`">{{ description }}</p></div>
      <button type="button" class="configuration-add" :disabled="disabled" :aria-label="`添加${title}`" @click="emit('update:modelValue', [...modelValue, ''])"><Plus :size="15" />添加</button>
    </div>
    <p v-if="!modelValue.length" class="configuration-empty">暂未设置，点击「添加」新增一项。</p>
    <div v-for="(item, index) in modelValue" :key="index" class="configuration-row">
      <span class="configuration-index">{{ String(index + 1).padStart(2, '0') }}</span>
      <button v-if="images" type="button" class="configuration-preview" :disabled="!previewUrl(item)" :aria-label="`查看${title} ${index + 1} 大图`" :aria-describedby="preview?.index === index ? `${field}-preview` : undefined" :title="previewUrl(item) ? '悬停查看大图' : item.trim() ? '图片无法加载，请检查地址或访问权限' : '填写图片地址后显示预览'" @mouseenter="openPreview(item, index, $event)" @mouseleave="closePreview" @focus="openPreview(item, index, $event)" @blur="closePreview" @click="openPreview(item, index, $event)">
        <img v-if="previewUrl(item)" :key="previewUrl(item)" :src="previewUrl(item)" :alt="`${title} ${index + 1} 缩略预览`" loading="lazy" decoding="async" referrerpolicy="no-referrer" @error="imageFailed(item, $event)" />
        <ImageIcon v-else :size="19" :aria-label="item.trim() ? '图片暂无法预览，请检查地址' : '图片预览'" />
      </button>
      <input :id="`${field}-${index}`" :value="item" :aria-label="`${title} ${index + 1}`" :aria-describedby="`${field}-description`" :disabled="disabled" :placeholder="images ? '填写图片地址，如 https://…' : '填写欢迎词或打卡口号'" :inputmode="images ? 'url' : 'text'" @input="setItem(index, $event)" />
      <button type="button" class="icon-button configuration-remove" :disabled="disabled" :aria-label="`删除${title} ${index + 1}`" @click="emit('update:modelValue', modelValue.filter((_, position) => position !== index))"><Trash2 :size="16" /></button>
    </div>
  </section>
  <Teleport to="body">
    <div v-if="preview" :id="`${field}-preview`" class="configuration-image-popover" role="tooltip" :style="{ left: `${preview.left}px`, top: `${preview.top}px`, width: `${preview.width}px`, height: `${preview.height}px` }">
      <img :src="preview.src" :alt="`${title} ${preview.index + 1} 大图`" referrerpolicy="no-referrer" @error="closePreview" />
    </div>
  </Teleport>
</template>
