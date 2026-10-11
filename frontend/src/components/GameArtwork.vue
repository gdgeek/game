<script setup lang="ts">
import { Gamepad2 } from 'lucide-vue-next'
withDefaults(defineProps<{ id: number; large?: boolean }>(), { large: false })
</script>

<template>
  <div class="game-artwork" :class="{ large }" aria-hidden="true">
    <div class="art-orbit orbit-one"></div>
    <div class="art-orbit orbit-two"></div>
    <Gamepad2 :size="large ? 46 : 18" :stroke-width="1.5" />
    <span v-if="large" class="art-id">场景 ID / {{ id }}</span>
  </div>
</template>
