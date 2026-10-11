<script setup lang="ts">
import { computed, ref } from 'vue'
import { X, Search, Check, ArrowRight, LoaderCircle, Info, AlertCircle } from 'lucide-vue-next'
import type { Device, Game } from '../types'
import GameArtwork from './GameArtwork.vue'
import { useModalFocus } from '../lib/useModalFocus'
const props = defineProps<{ device: Device; games: Game[]; busy: boolean; error: string }>()
const emit = defineEmits<{ close: []; save: [gameId: number] }>()
const selected = ref<number | null>(props.device.gameId)
const search = ref('')
const dialog = ref<HTMLElement>()
const filtered = computed(() => props.games.filter(game => `${game.name} ${game.id} ${game.category}`.toLowerCase().includes(search.value.toLowerCase())))
const selectedGame = computed(() => props.games.find(game => game.id === selected.value))
useModalFocus(dialog, () => emit('close'), () => props.busy)
</script>

<template>
  <div class="modal-backdrop" @click.self="!busy && emit('close')">
    <section ref="dialog" class="game-picker" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="picker-title" :aria-busy="busy">
      <header class="modal-header"><div><span class="eyebrow">设备配置</span><h2 id="picker-title">选择游戏场景</h2><p>{{ device.name || '未命名设备' }}<span class="inline-divider">/</span><span class="mono">设备 ID {{ device.id }}</span></p><p v-if="device.uuid" class="mono picker-device-uuid">UUID {{ device.uuid }}</p></div><button class="icon-button" aria-label="关闭游戏选择" :disabled="busy" @click="emit('close')"><X :size="21" /></button></header>
      <div class="picker-content">
        <label class="search-input"><Search :size="18" /><input v-model="search" aria-label="搜索可用游戏" placeholder="搜索游戏名称或场景 ID" :disabled="busy" /></label>
        <div class="picker-games" role="group" aria-label="选择游戏">
          <button v-for="game in filtered" :key="game.id" class="picker-game" :class="{ selected: selected === game.id }" :aria-pressed="selected === game.id" :disabled="busy" @click="selected = game.id">
            <GameArtwork :id="game.id" /><span class="picker-game-text"><strong>{{ game.name }}</strong><small>{{ game.category || '游戏场景' }}<span>场景 ID {{ game.id }}</span></small></span><span class="radio-check"><Check v-if="selected === game.id" :size="13" /></span>
          </button>
        </div>
        <p v-if="!filtered.length" class="picker-empty">没有匹配的游戏，试试其他关键词。</p>
        <p class="picker-info"><Info :size="16" />{{ device.status === 'offline' ? '设备当前离线。保存后，设备下次读取配置时获取新游戏。' : '保存后更新此设备的游戏配置，设备下次读取时获取新游戏。' }}</p>
        <p v-if="error" class="form-error" role="alert"><AlertCircle :size="16" />{{ error }}</p>
      </div>
      <footer class="modal-footer"><div class="picker-selection"><span>已选择</span><strong>{{ selectedGame?.name || '尚未选择游戏' }}</strong></div><div class="button-group"><button class="button secondary" :disabled="busy" @click="emit('close')">取消</button><button class="button primary" :disabled="busy || selected === null || selected === device.gameId" @click="selected !== null && emit('save', selected)"><LoaderCircle v-if="busy" :size="16" class="spin" />{{ busy ? '正在保存…' : '保存配置' }}<ArrowRight v-if="!busy" :size="16" /></button></div></footer>
    </section>
  </div>
</template>
