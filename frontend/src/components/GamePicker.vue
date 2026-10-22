<script setup lang="ts">
import { computed, ref } from 'vue'
import { X, Search, Check, ArrowRight, LoaderCircle, Info, AlertCircle } from 'lucide-vue-next'
import type { Device, DeviceConfigurationPatch, Game } from '../types'
import GameArtwork from './GameArtwork.vue'
import ConfigurationList from './ConfigurationList.vue'
import { configurationChanges, createConfigurationDraft } from '../lib/device-configuration'
import { useModalFocus } from '../lib/useModalFocus'
const props = defineProps<{ device: Device; games: Game[]; busy: boolean; error: string; gamesError?: string }>()
const emit = defineEmits<{ close: []; save: [configuration: DeviceConfigurationPatch] }>()
const draft = ref(createConfigurationDraft(props.device))
const search = ref('')
const dialog = ref<HTMLElement>()
const filtered = computed(() => props.games.filter(game => `${game.name} ${game.id} ${game.category}`.toLowerCase().includes(search.value.toLowerCase())))
const selectedGame = computed(() => props.games.find(game => game.id === draft.value.gameId))
const selectedLabel = computed(() => selectedGame.value?.name || (draft.value.gameId === null ? '尚未选择游戏' : `场景 ${draft.value.gameId}`))
const changes = computed(() => configurationChanges(props.device, draft.value))
const dirty = computed(() => Object.keys(changes.value).length > 0)
const titleError = computed(() => Array.from(draft.value.title.trim()).length > 255 ? '设备名称最多 255 个字符' : '')
function save() { if (!props.busy && dirty.value && !titleError.value) emit('save', changes.value) }
useModalFocus(dialog, () => emit('close'), () => props.busy)
</script>

<template>
  <div class="modal-backdrop" @click.self="!busy && emit('close')">
    <section ref="dialog" class="game-picker" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="picker-title" :aria-busy="busy">
      <header class="modal-header"><div><span class="eyebrow">设备配置</span><h2 id="picker-title">编辑设备配置</h2><p>{{ device.name || '未命名设备' }}<span class="inline-divider">/</span><span class="mono">设备 ID {{ device.id }}</span></p><p v-if="device.uuid" class="mono picker-device-uuid">UUID {{ device.uuid }}</p></div><button class="icon-button" aria-label="关闭设备配置" :disabled="busy" @click="emit('close')"><X :size="21" /></button></header>
      <div class="picker-content">
        <div class="configuration-name">
          <label for="device-title">设备名称</label>
          <input id="device-title" v-model="draft.title" :disabled="busy" :aria-invalid="!!titleError" aria-describedby="device-title-description device-title-error" placeholder="填写设备名称" />
          <p id="device-title-description">最多 255 个字符；留空时显示设备标签。</p>
          <p v-if="titleError" id="device-title-error" class="form-error" role="alert">{{ titleError }}</p>
        </div>
        <h3 class="configuration-game-title">游戏场景</h3>
        <label class="search-input"><Search :size="18" /><input v-model="search" aria-label="搜索可用游戏" placeholder="搜索游戏名称或场景 ID" :disabled="busy" /></label>
        <div class="picker-games" role="group" aria-label="选择游戏">
          <button v-for="game in filtered" :key="game.id" class="picker-game" :class="{ selected: draft.gameId === game.id }" :aria-pressed="draft.gameId === game.id" :disabled="busy" @click="draft.gameId = game.id">
            <GameArtwork :id="game.id" /><span class="picker-game-text"><strong>{{ game.name }}</strong><small>{{ game.category || '游戏场景' }}<span>场景 ID {{ game.id }}</span></small></span><span class="radio-check"><Check v-if="draft.gameId === game.id" :size="13" /></span>
          </button>
        </div>
        <p v-if="gamesError || !games.length" class="configuration-catalog-note">{{ gamesError || '暂无可用游戏。' }} 仍可修改设备名称、下方欢迎词和图片。</p>
        <p v-else-if="!filtered.length" class="picker-empty">没有匹配的游戏，试试其他关键词。</p>
        <ConfigurationList v-model="draft.slogans" field="slogans" title="欢迎词（口号）" description="填写设备使用的欢迎词或打卡标语，可添加多条。" :disabled="busy" />
        <ConfigurationList v-model="draft.pictures" field="pictures" title="图片" description="填写完整图片地址，按列表顺序保存。" images :disabled="busy" />
        <ConfigurationList v-model="draft.thumbs" field="thumbs" title="缩略图" description="用于选择封面，请与图片保持相同的顺序。" images :disabled="busy" />
        <p class="picker-info"><Info :size="16" />{{ device.status === 'offline' ? '设备当前离线，设备下次读取时获取新配置。' : '保存后，设备下次读取时获取新的游戏、欢迎词和图片配置。' }}</p>
      </div>
      <p v-if="error" class="form-error configuration-error" role="alert"><AlertCircle :size="16" />{{ error }}</p>
      <footer class="modal-footer"><div class="picker-selection"><span>当前选择的游戏</span><strong>{{ selectedLabel }}</strong></div><div class="button-group"><button class="button secondary" :disabled="busy" @click="emit('close')">取消</button><button class="button primary" :disabled="busy || !dirty || !!titleError" @click="save"><LoaderCircle v-if="busy" :size="16" class="spin" />{{ busy ? '正在保存…' : '保存配置' }}<ArrowRight v-if="!busy" :size="16" /></button></div></footer>
    </section>
  </div>
</template>
