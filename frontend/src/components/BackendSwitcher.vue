<script setup lang="ts">
import { Check, Server } from 'lucide-vue-next'
import type { BackendId } from '../types'
import { BACKENDS } from '../lib/api'

withDefaults(defineProps<{ modelValue: BackendId; disabled?: boolean; compact?: boolean }>(), { disabled: false, compact: false })
const emit = defineEmits<{ 'update:modelValue': [backend: BackendId] }>()
function select(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  if (value === 'b0' || value === 'b1') emit('update:modelValue', value)
}
</script>

<template>
  <label v-if="compact" class="backend-compact">
    <Server :size="15" aria-hidden="true" />
    <select :value="modelValue" :disabled="disabled" aria-label="切换正式后端" @change="select">
      <option v-for="backend in BACKENDS" :key="backend.id" :value="backend.id">{{ backend.name }} · {{ backend.host }}</option>
    </select>
  </label>
  <fieldset v-else class="backend-selector" :disabled="disabled">
    <legend>选择正式后端</legend>
    <div class="backend-options">
      <button v-for="backend in BACKENDS" :key="backend.id" type="button" :aria-pressed="modelValue === backend.id" :class="{ selected: modelValue === backend.id }" :disabled="disabled" @click="emit('update:modelValue', backend.id)">
        <span><strong>{{ backend.name }}</strong><small>{{ backend.host }}</small></span>
        <Check v-if="modelValue === backend.id" :size="15" aria-hidden="true" />
      </button>
    </div>
    <p>两个后端均为正式环境，请选择要管理的设备。</p>
  </fieldset>
</template>

<style scoped>
.backend-selector{border:0;padding:0;margin:0 0 24px;min-width:0}.backend-selector legend{padding:0;margin-bottom:9px;color:#353535;font-size:12px;font-weight:500}.backend-options{display:grid;grid-template-columns:1fr 1fr;gap:9px}.backend-options button{display:flex;align-items:center;justify-content:space-between;gap:5px;padding:11px 12px;text-align:left;border:1px solid #e6e6e6;border-radius:6px;color:#666;background:#fff;min-width:0}.backend-options button.selected{border-color:#07c160;background:#f2fbf6;color:#079b4f}.backend-options button:hover:not(:disabled){border-color:#07c160}.backend-options strong{display:block;font-size:11px;font-weight:500}.backend-options small{display:block;margin-top:5px;font-size:9px;color:#8d8d8d;white-space:nowrap}.backend-selector p{font-size:10px;line-height:1.6;color:#999;margin:8px 0 0}.backend-compact{display:flex;align-items:center;gap:7px;color:#666}.backend-compact select{background:#fff;border:1px solid #e5e5e5;border-radius:5px;padding:7px 9px;color:#555;max-width:245px;font-size:10px;cursor:pointer}.backend-compact select:disabled,.backend-options button:disabled{opacity:.55;cursor:wait}.backend-options button:focus-visible,.backend-compact select:focus-visible{outline:2px solid #07c160;outline-offset:3px}@media(max-width:600px){.backend-compact select{max-width:154px;font-size:9px;padding:6px}.backend-compact>svg{display:none}.backend-options button{padding:10px}.backend-options small{font-size:8px}}
</style>
