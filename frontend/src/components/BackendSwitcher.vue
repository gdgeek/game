<script setup lang="ts">
import { Server } from 'lucide-vue-next'
import type { BackendId, BackendOption } from '../types'

const props = withDefaults(defineProps<{ backends: readonly BackendOption[]; modelValue: BackendId; disabled?: boolean; compact?: boolean }>(), { disabled: false, compact: false })
const emit = defineEmits<{ 'update:modelValue': [backend: BackendId] }>()
function select(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  if (!props.disabled && props.backends.some(backend => backend.id === value)) emit('update:modelValue', value)
}
</script>

<template>
  <label v-if="backends.length > 1 && compact" class="backend-compact">
    <Server :size="15" aria-hidden="true" />
    <select :value="modelValue" :disabled="disabled" aria-label="切换正式后端" @change="select">
      <option v-for="backend in backends" :key="backend.id" :value="backend.id">{{ backend.name }} · {{ backend.host }}</option>
    </select>
  </label>
  <fieldset v-else-if="backends.length > 1" class="backend-selector" :disabled="disabled">
    <legend>选择正式后端</legend>
    <select :value="modelValue" :disabled="disabled" aria-label="选择正式后端" @change="select">
      <option v-for="backend in backends" :key="backend.id" :value="backend.id">{{ backend.name }} · {{ backend.host }}</option>
    </select>
    <p>请选择要管理的设备所在的后端。</p>
  </fieldset>
</template>

<style scoped>
.backend-selector{border:0;padding:0;margin:0 0 24px;min-width:0}.backend-selector legend{padding:0;margin-bottom:9px;color:#353535;font-size:12px;font-weight:500}.backend-selector select{width:100%;padding:11px 12px;border:1px solid #e6e6e6;border-radius:6px;color:#555;background:#fff;min-width:0;font-size:11px;cursor:pointer}.backend-selector p{font-size:10px;line-height:1.6;color:#999;margin:8px 0 0}.backend-compact{display:flex;align-items:center;gap:7px;color:#666}.backend-compact select{background:#fff;border:1px solid #e5e5e5;border-radius:5px;padding:7px 9px;color:#555;max-width:245px;font-size:10px;cursor:pointer}.backend-compact select:disabled,.backend-selector select:disabled{opacity:.55;cursor:wait}.backend-selector select:focus-visible,.backend-compact select:focus-visible{outline:2px solid #07c160;outline-offset:3px}@media(max-width:600px){.backend-compact select{max-width:154px;font-size:9px;padding:6px}.backend-compact>svg{display:none}}
</style>
