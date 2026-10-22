<script setup lang="ts">
import { ref } from 'vue'
import { Image as ImageIcon, Plus, Trash2 } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: string[]
  title: string
  field: string
  description: string
  disabled: boolean
  images?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [items: string[]] }>()
const failedImages = ref(new Set<string>())
function setItem(index: number, event: Event) {
  const items = [...props.modelValue]
  items[index] = (event.target as HTMLInputElement).value
  emit('update:modelValue', items)
}
function previewUrl(value: string) {
  try {
    const url = new URL(value.trim())
    return ['http:', 'https:'].includes(url.protocol) && !failedImages.value.has(value) ? url.href : ''
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
      <span v-if="images" class="configuration-preview">
        <img v-if="previewUrl(item)" :src="previewUrl(item)" :alt="`${title} ${index + 1} 预览`" loading="lazy" referrerpolicy="no-referrer" @error="failedImages.add(item)" />
        <ImageIcon v-else :size="19" :aria-label="item.trim() ? '图片暂无法预览，请检查地址' : '图片预览'" />
      </span>
      <input :id="`${field}-${index}`" :value="item" :aria-label="`${title} ${index + 1}`" :aria-describedby="`${field}-description`" :disabled="disabled" :placeholder="images ? '填写图片地址，如 https://…' : '填写欢迎词或打卡口号'" :inputmode="images ? 'url' : 'text'" @input="setItem(index, $event)" />
      <button type="button" class="icon-button configuration-remove" :disabled="disabled" :aria-label="`删除${title} ${index + 1}`" @click="emit('update:modelValue', modelValue.filter((_, position) => position !== index))"><Trash2 :size="16" /></button>
    </div>
  </section>
</template>
