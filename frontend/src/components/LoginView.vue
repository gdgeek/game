<script setup lang="ts">
import { ref } from 'vue'
import { ArrowRight, Eye, EyeOff, LockKeyhole, Monitor, ShieldCheck, UserRound, LoaderCircle, AlertCircle } from 'lucide-vue-next'
defineProps<{ busy: boolean; error: string; demo: boolean }>()
const emit = defineEmits<{ login: [username: string, password: string] }>()
const username = ref('dirui')
const password = ref('')
const visible = ref(false)
const baseUrl = import.meta.env.BASE_URL
function submit() {
  if (username.value.trim() && password.value) emit('login', username.value.trim(), password.value)
}
</script>

<template>
  <main class="login-page">
    <section class="login-story" aria-label="不加班设备管理">
      <a class="brand login-brand" :href="baseUrl" aria-label="不加班首页"><span class="brand-symbol">不</span>不加班<span class="brand-caption">BUJIABAN</span></a>
      <div class="story-body">
        <span class="eyebrow light">设备管理</span>
        <h1>查看设备，<br />配置游戏场景<span class="green-period">.</span></h1>
        <p>选择设备，为它设置合适的游戏场景。</p>
        <div class="device-scene" aria-hidden="true">
          <div class="scene-ring ring-a"></div><div class="scene-ring ring-b"></div>
          <div class="scene-monitor monitor-back"><Monitor :size="70" :stroke-width="1" /></div>
          <div class="scene-monitor monitor-front"><div class="screen-dots"><i></i><i></i><i></i></div><Monitor :size="110" :stroke-width="0.85" /><span>DEVICE / SCENE</span><div class="screen-line"></div></div>
          <div class="connection-badge"><span class="status-dot online"></span>设备 · 游戏场景</div>
          <div class="scene-coordinates">BUJIABAN / DEVICE MANAGEMENT</div>
        </div>
      </div>
      <div class="story-footer"><span>不加班 · 设备管理后台</span><span>查看 / 配置 / 保存</span></div>
    </section>
    <section class="login-form-side">
      <span class="login-version">设备管理中心 <span>v1.0</span></span>
      <div class="login-form-wrap">
        <div class="login-lock"><LockKeyhole :size="24" :stroke-width="1.6" /></div>
        <h2>登录管理后台</h2><p class="login-description">查看设备列表与游戏场景配置。</p>
        <slot name="environment" />
        <form @submit.prevent="submit">
          <label for="username">用户名</label>
          <div class="input-with-icon"><UserRound :size="18" /><input id="username" v-model="username" name="username" autocomplete="username" required :disabled="busy" placeholder="输入用户名" /></div>
          <label for="password">密码</label>
          <div class="input-with-icon"><LockKeyhole :size="18" /><input id="password" v-model="password" :type="visible ? 'text' : 'password'" name="password" autocomplete="current-password" required :disabled="busy" placeholder="输入登录密码" /><button class="icon-button password-toggle" type="button" :aria-label="visible ? '隐藏密码' : '显示密码'" @click="visible = !visible"><EyeOff v-if="visible" :size="18" /><Eye v-else :size="18" /></button></div>
          <p v-if="error" class="form-error" role="alert"><AlertCircle :size="16" />{{ error }}</p>
          <button type="submit" class="button primary login-submit" :disabled="busy || !username.trim() || !password"><LoaderCircle v-if="busy" :size="18" class="spin" /><span>{{ busy ? '正在登录…' : '登录管理后台' }}</span><ArrowRight v-if="!busy" :size="18" /></button>
        </form>
        <p class="login-security"><ShieldCheck :size="15" />账号由管理员分配，设备配置仅授权用户可见</p>
        <div v-if="demo" class="login-demo"><span class="status-dot amber"></span>本地演示环境 · 使用已分配的账号登录</div>
      </div>
      <footer class="login-footer"><span>© {{ new Date().getFullYear() }} 不加班</span><span>设备管理</span></footer>
    </section>
  </main>
</template>
