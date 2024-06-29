import { showError } from '@nextcloud/dialogs'

let mytimer = 0
export function delay(callback, ms) {
	return function() {
		const context = this
		const args = arguments
		clearTimeout(mytimer)
		mytimer = setTimeout(function() {
			callback.apply(context, args)
		}, ms || 0)
	}
}

export function humanFileSize(bytes, approx = false, si = false, dp = 1) {
	const thresh = si ? 1000 : 1024

	if (Math.abs(bytes) < thresh) {
		return bytes + ' B'
	}

	const units = si
		? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
		: ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB']
	let u = -1
	const r = 10 ** dp

	do {
		bytes /= thresh
		++u
	} while (Math.round(Math.abs(bytes) * r) / r >= thresh && u < units.length - 1)

	if (approx) {
		return Math.floor(bytes) + ' ' + units[u]
	} else {
		return bytes.toFixed(dp) + ' ' + units[u]
	}
}

function getDetails(error) {
	try {
		const html = error.response?.request?.responseText
		if (!html) {
			throw Error('Not an HTML response')
		}

		const parser = new DOMParser()
		const htmlDoc = parser.parseFromString(html, 'text/html')
		return htmlDoc.querySelector('main').innerHTML
	} catch (e) {
		const json = JSON.stringify(error, Object.getOwnPropertyNames(error), 2)
		return `<pre><code>${json}</code></pre>`
	}
}

export function showServerError(error, message) {
	// In the worst case, I can instruct people to dig through the browser console
	// in GitHub issues.
	console.error(error)

	const summary = t('google_synchronization', 'Details')
	const details = getDetails(error)

	showError(`
		<div style="padding: 10px;">
			<h2>${message}: ${error.message}</h2>
			<details>
				<summary>${summary}</summary>
				${details}
			</details>
		</div>`, { isHTML: true })
}
