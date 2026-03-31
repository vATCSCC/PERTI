import { createI18n } from 'vue-i18n'
import enUS from './en-US.json'
import frCA from './fr-CA.json'

const locale = localStorage.getItem('PERTI_LOCALE')
  || navigator.language
  || 'en-US'

export const i18n = createI18n({
  legacy: false,
  locale,
  fallbackLocale: 'en-US',
  messages: {
    'en-US': enUS,
    'fr-CA': frCA,
  },
})
