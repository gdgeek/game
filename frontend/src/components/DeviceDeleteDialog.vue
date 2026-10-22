<script setup lang="ts">
import { ref } from 'vue'
import { AlertCircle, LoaderCircle, Trash2, X } from 'lucide-vue-next'
import type { Device } from '../types'
import { useModalFocus } from '../lib/useModalFocus'

const props = defineProps<{ device: Device; busy: boolean; error: string }>()
const emit = defineEmits<{ close: []; confirm: [] }>()
const dialog = ref<HTMLElement>()
function close() { if (!props.busy) emit('close') }
function confirm() { if (!props.busy) emit('confirm') }
useModalFocus(dialog, close, () => props.busy)
</script>

<template>
  <div class="modal-backdrop" @click.self="close">
    <section ref="dialog" class="device-delete-dialog" tabindex="-1" role="alertdialog" aria-modal="true" aria-labelledby="delete-device-title" aria-describedby="delete-device-description" :aria-busy="busy">
      <header class="modal-header"><div><span class="eyebrow">设备管理</span><h2 id="delete-device-title">确认删除设备？</h2></div><button class="icon-button" aria-label="关闭删除确认" :disabled="busy" @click="close"><X :size="21" /></button></header>
      <div class="device-delete-content">
        <div class="device-delete-target"><Trash2 :size="22" /><div><strong>{{ device.name || `设备 #${device.id}` }}</strong><span class="mono">设备 ID {{ device.id }}</span><span v-if="device.uuid" class="mono">UUID {{ device.uuid }}</span></div></div>
        <p id="delete-device-description">删除后将移除该设备及其配置、管理关联，历史打卡记录保留。此操作无法撤销。</p>
        <p class="device-delete-note">设备再次上报时，可能重新出现在列表中。</p>
        <p v-if="error" class="form-error" role="alert"><AlertCircle :size="16" />{{ error }}</p>
      </div>
      <footer class="modal-footer"><div class="button-group"><button class="button secondary" :disabled="busy" @click="close">取消</button><button class="button danger" :disabled="busy" @click="confirm"><LoaderCircle v-if="busy" :size="16" class="spin" /><Trash2 v-else :size="16" />{{ busy ? '正在删除…' : '确认删除' }}</button></div></footer>
    </section>
  </div>
</template>
