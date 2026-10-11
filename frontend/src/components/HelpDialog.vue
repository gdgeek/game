<script setup lang="ts">
import { ref } from 'vue'
import { Check, ShieldCheck, X } from 'lucide-vue-next'
import { useModalFocus } from '../lib/useModalFocus'
defineProps<{ demo: boolean }>()
const emit = defineEmits<{ close: [] }>()
const dialog = ref<HTMLElement>()
useModalFocus(dialog, () => emit('close'))
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')"><section ref="dialog" class="help-dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="help-title"><header class="modal-header"><div><span class="eyebrow">使用帮助</span><h2 id="help-title">设备管理</h2></div><button class="icon-button" aria-label="关闭帮助" @click="emit('close')"><X :size="20" /></button></header><div class="help-content"><div><span>01</span><section><h3>查看设备列表</h3><p>查看设备总数，按设备名称、ID、UUID、IP 或游戏筛选。统计始终代表全部设备。</p></section></div><div><span>02</span><section><h3>选择游戏场景</h3><p>点击设备右侧的「配置游戏」，选择场景并点击「保存配置」。设备下次读取配置时获取新游戏，设备的其他配置会保留。</p></section></div><div><span>03</span><section><h3>{{ demo ? '当前是本地演示环境' : '配置与状态' }}</h3><p>{{ demo ? '此环境包含 12 台样例设备，配置保存在当前电脑。' : '游戏场景配置保存在服务端。当前接口不提供设备心跳，因此设备显示“状态未知”。' }}</p></section></div></div><footer class="modal-footer"><span class="help-footer"><ShieldCheck :size="15" />仅管理员可以管理设备配置</span><button class="button primary" @click="emit('close')">知道了<Check :size="15" /></button></footer></section></div>
</template>
