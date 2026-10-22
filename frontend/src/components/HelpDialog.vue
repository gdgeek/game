<script setup lang="ts">
import { ref } from 'vue'
import { Check, ShieldCheck, X } from 'lucide-vue-next'
import { useModalFocus } from '../lib/useModalFocus'
defineProps<{ demo: boolean; deviceManager?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const dialog = ref<HTMLElement>()
useModalFocus(dialog, () => emit('close'))
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')"><section ref="dialog" class="help-dialog" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="help-title"><header class="modal-header"><div><span class="eyebrow">使用帮助</span><h2 id="help-title">设备管理</h2></div><button class="icon-button" aria-label="关闭帮助" @click="emit('close')"><X :size="20" /></button></header><div class="help-content"><div><span>01</span><section><h3>查看设备列表</h3><p>查看设备总数，按设备名称、ID、UUID、IP 或游戏筛选。{{ deviceManager ? '统计仅包含你关联的设备。' : '统计始终代表全部设备。' }}</p></section></div><div><span>02</span><section><h3>编辑设备配置</h3><p>点击设备右侧的「配置设备」，修改设备名称、选择游戏，编辑欢迎词（口号）、图片和缩略图地址。列表支持新增和删除，点击「保存配置」后，设备下次读取时获取新配置。取消会放弃本次修改。</p></section></div><div><span>03</span><section><h3>{{ demo ? '当前是本地演示环境' : '配置与状态' }}</h3><p>{{ demo ? '此环境包含 12 台样例设备，配置保存在当前电脑。' : '设备名称、游戏、欢迎词和图片配置保存在服务端。当前接口不提供设备心跳，因此设备显示“状态未知”。' }}</p></section></div></div><footer class="modal-footer"><span class="help-footer"><ShieldCheck :size="15" />{{ deviceManager ? '仅可管理当前账号关联的设备' : '管理员可以管理全部设备配置' }}</span><button class="button primary" @click="emit('close')">知道了<Check :size="15" /></button></footer></section></div>
</template>
