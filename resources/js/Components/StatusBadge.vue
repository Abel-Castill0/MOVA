<template>
  <span :class="['inline-block px-2 py-0.5 rounded-full text-xs font-semibold', style.color]">{{ style.label ?? status }}</span>
</template>

<script setup>
import { computed } from 'vue'
import { statusStyle } from '@/utils/statusColors'

const props = defineProps({ status: String })

const style = computed(() => statusStyle(props.status))
</script>
