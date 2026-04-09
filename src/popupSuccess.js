import { loadState } from '@nextcloud/initial-state'

const state = loadState('integration_google', 'popup-data')
const username = state.user_name

const notifyOpener = (message) => {
	try {
		if (typeof BroadcastChannel !== 'undefined') {
			const bc = new BroadcastChannel('integration_google_oauth')
			try {
				bc.postMessage(message)
				return
			} finally {
				bc.close()
			}
		}
	} catch (e) {
		// fall through to same-origin opener fallback
	}
	try {
		if (window.opener && window.opener.location?.origin === window.location.origin) {
			window.opener.postMessage(message, window.location.origin)
		}
	} catch (e) {
		// ignore cross-origin/access errors
	}
}

notifyOpener({ username })
window.close()
