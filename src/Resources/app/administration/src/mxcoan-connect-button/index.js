const { Component } = Shopware

/**
 * "Connect with MetriXs" button for the plugin config page (config.xml
 * custom component). Flow: POST /api/_action/mxcoan/connect/start (the
 * admin API needs an explicit Authorization header, taken from the login
 * service) → the controller stores a single-use state and returns the
 * dashboard connect URL → full-page redirect to the dashboard → back via the
 * storefront callback → the config page shows the connected state on reload.
 *
 * Copy is EN + DE (the Shopware market is DACH). Snippet files only load with
 * a registered admin module, which a single config-page component does not
 * have, so the strings live here and follow the admin session language.
 */
const STRINGS = {
  en: {
    connect: 'Connect with MetriXs',
    connecting: 'Starting connection …',
    failed: 'Could not start the connection. Check the MetriXs API URL, save, and try again.',
    forbidden:
      'Your admin role is not allowed to change plugin settings, so it cannot start the MetriXs connection.',
  },
  de: {
    connect: 'Mit MetriXs verbinden',
    connecting: 'Verbindung wird gestartet …',
    failed:
      'Die Verbindung konnte nicht gestartet werden. Prüfen Sie die MetriXs-API-URL, speichern Sie und versuchen Sie es erneut.',
    forbidden:
      'Ihre Admin-Rolle darf keine Plugin-Einstellungen ändern und kann die MetriXs-Verbindung daher nicht starten.',
  },
}

function stringsFor(locale) {
  const value = String(locale || '')
    .replace('_', '-')
    .toLowerCase()

  return value.startsWith('de') ? STRINGS.de : STRINGS.en
}

function adminSessionLocale() {
  try {
    const session = Shopware.State?.get?.('session')

    return session?.currentLanguageVersion || session?.currentLanguage || 'en-GB'
  } catch {
    return 'en-GB'
  }
}

function adminBearerToken() {
  const services = Shopware.Application?.getContainer?.('service') || {}
  const loginService =
    services.loginService ||
    (typeof Shopware.Service === 'function' ? Shopware.Service('loginService') : null)

  if (!loginService || typeof loginService.getToken !== 'function') {
    throw new Error('no_login_service')
  }

  return loginService.getToken()
}

Component.register('mxcoan-connect-button', {
  template: `
        <div class="mxcoan-connect">
            <sw-button
                variant="primary"
                :isLoading="isLoading"
                :disabled="isLoading"
                @click="onConnect"
            >
                {{ isLoading ? text.connecting : text.connect }}
            </sw-button>
            <p v-if="error" class="mxcoan-connect__error">
                {{ error }}
            </p>
        </div>
    `,

  data() {
    return {
      isLoading: false,
      error: '',
    }
  },

  computed: {
    text() {
      return stringsFor(adminSessionLocale())
    },
  },

  methods: {
    async onConnect() {
      this.isLoading = true
      this.error = ''
      try {
        const resp = await fetch('/api/_action/mxcoan/connect/start', {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${adminBearerToken()}`,
          },
        })
        if (resp.status === 401 || resp.status === 403) {
          throw new Error('forbidden')
        }
        if (!resp.ok) {
          throw new Error('start_failed')
        }
        const data = await resp.json()
        if (!data.url) {
          throw new Error('no_url')
        }
        // Full-page redirect: the dashboard connect flow ends with a
        // redirect back into the administration (storefront callback
        // → /admin#/sw/extension/config/Mxcoan).
        window.location.href = data.url
      } catch (e) {
        this.error = e?.message === 'forbidden' ? this.text.forbidden : this.text.failed
        this.isLoading = false
      }
    },
  },
})
