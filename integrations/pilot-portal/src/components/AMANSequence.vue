<template>
  <div class="card">
    <h3>{{ $t('aman.title') }}</h3>
    <p>{{ $t('aman.no_sequence') }}</p>
    <!-- AMAN data will come via WebSocket when available -->
  </div>
</template>

<script setup>
</script>

<style scoped>
.card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; }
</style>
