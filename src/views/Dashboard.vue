<template>
	<DashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="state === 'loading'"
		:item-menu="itemMenu"
		@unsubscribe="onUnsubscribe"
		@markRead="onMarkRead">
		<!-- if we want to override the item component -->
		<!--template v-slot:default="{ item }">
			{{ item.mainText }}
		</template-->

		<!-- if we want to define the item popover (works with DashboardWidgetItem component only) -->
		<!--template v-slot:popover="{ item }">
			<h3>{{ item.subText }}</h3>
			{{ item.mainText }}<br/>
			{{ item.popFormattedDate }}<br/><br/>
			{{ item.popContent }}
		</template-->
		<template v-slot:empty-content>
			<div v-if="state === 'no-token'">
				<a :href="settingsUrl">
					{{ t('integration_google', 'Click here to configure the access to your Google account.') }}
				</a>
			</div>
			<div v-else-if="state === 'error'">
				<a :href="settingsUrl">
					{{ t('integration_google', 'Incorrect access token.') }}
					{{ t('integration_google', 'Click here to configure the access to your Google account.') }}
				</a>
			</div>
			<div v-else-if="state === 'ok'">
				{{ t('integration_google', 'Nothing to show') }}
			</div>
		</template>
	</DashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { DashboardWidget } from '@nextcloud/vue-dashboard'

export default {
	name: 'Dashboard',

	components: {
		DashboardWidget,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			notifications: [],
			showMoreUrl: 'https://google.com/notifications',
			showMoreText: t('integration_google', 'Google notifications'),
			// lastDate could be computed but we want to keep the value when first notification is removed
			// to avoid getting it again on next request
			lastDate: null,
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			darkThemeColor: OCA.Accessibility.theme === 'dark' ? '181818' : 'ffffff',
			itemMenu: {
				markRead: {
					text: t('integration_google', 'Mark as read'),
					icon: 'icon-checkmark',
				},
				unsubscribe: {
					text: t('integration_google', 'Unsubscribe'),
					icon: 'icon-google-unsubscribe',
				},
			},
		}
	},

	computed: {
		items() {
			return this.notifications.map((n) => {
				return {
					id: n.id,
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getRepositoryAvatarUrl(n),
					// avatarUsername: '',
					overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: n.subject.title,
					subText: this.getSubline(n),
				}
			})
		},
		lastMoment() {
			return moment(this.lastDate)
		},
	},

	beforeMount() {
		this.fetchNotifications()
		this.loop = setInterval(() => this.fetchNotifications(), 15000)
	},

	mounted() {
	},

	methods: {
		fetchNotifications() {
			const req = {}
			if (this.lastDate) {
				req.params = {
					since: this.lastDate,
				}
			}
			axios.get(generateUrl('/apps/integration_google/notifications'), req).then((response) => {
				this.processNotifications(response.data)
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_google', 'Failed to get Google notifications.'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					console.debug(error)
				}
			})
		},
		processNotifications(newNotifications) {
			if (this.lastDate) {
				// just add those which are more recent than our most recent one
				let i = 0
				while (i < newNotifications.length && this.lastMoment.isBefore(newNotifications[i].updated_at)) {
					i++
				}
				if (i > 0) {
					const toAdd = this.filter(newNotifications.slice(0, i))
					this.notifications = toAdd.concat(this.notifications)
				}
			} else {
				// first time we don't check the date
				this.notifications = this.filter(newNotifications)
			}
			// update lastDate manually (explained in data)
			const nbNotif = this.notifications.length
			this.lastDate = (nbNotif > 0) ? this.notifications[0].updated_at : null
		},
		filter(notifications) {
			// only keep the unread ones with specific reasons
			return notifications.filter((n) => {
				return (n.unread && ['assign', 'mention', 'review_requested'].includes(n.reason))
			})
		},
		onUnsubscribe(item) {
			const i = this.notifications.findIndex((n) => n.id === item.id)
			if (i !== -1) {
				this.notifications.splice(i, 1)
			}
			this.editNotification(item, 'unsubscribe')
		},
		onMarkRead(item) {
			const i = this.notifications.findIndex((n) => n.id === item.id)
			if (i !== -1) {
				this.notifications.splice(i, 1)
			}
			this.editNotification(item, 'mark-read')
		},
		editNotification(item, action) {
			axios.put(generateUrl('/apps/integration_google/notifications/' + item.id + '/' + action)).then((response) => {
			}).catch((error) => {
				showError(t('integration_google', 'Failed to edit Google notification.'))
				console.debug(error)
			})
		},
		getRepositoryAvatarUrl(n) {
			return (n.repository && n.repository.owner && n.repository.owner.avatar_url)
				? generateUrl('/apps/integration_google/avatar?') + encodeURIComponent('url') + '=' + encodeURIComponent(n.repository.owner.avatar_url)
				: ''
		},
		getNotificationTarget(n) {
			return n.subject.url
				.replace('api.google.com', 'google.com')
				.replace('/repos/', '/')
				.replace('/pulls/', '/pull/')
		},
		getNotificationContent(n) {
			// reason : mention, comment, review_requested, state_change
			if (n.reason === 'mention') {
				if (n.subject.type === 'PullRequest') {
					return t('integration_google', 'You were mentioned in a pull request')
				} else if (n.subject.type === 'Issue') {
					return t('integration_google', 'You were mentioned in an issue')
				}
			} else if (n.reason === 'comment') {
				return t('integration_google', 'Comment')
			} else if (n.reason === 'review_requested') {
				return t('integration_google', 'Your review was requested')
			} else if (n.reason === 'state_change') {
				if (n.subject.type === 'PullRequest') {
					return t('integration_google', 'Pull request state changed')
				} else if (n.subject.type === 'Issue') {
					return t('integration_google', 'Issue state changed')
				}
			} else if (n.reason === 'assign') {
				return t('integration_google', 'You are assigned')
			}
			return ''
		},
		getNotificationActionChar(n) {
			if (['review_requested', 'assign'].includes(n.reason)) {
				return '👁 '
			} else if (['comment', 'mention'].includes(n.reason)) {
				return '🗨 '
			}
			return ''
		},
		getSubline(n) {
			return this.getNotificationActionChar(n) + ' ' + n.repository.name + this.getTargetIdentifier(n)
		},
		getNotificationTypeImage(n) {
			if (n.subject.type === 'PullRequest') {
				return generateUrl('/svg/integration_google/pull_request?color=ffffff')
			} else if (n.subject.type === 'Issue') {
				return generateUrl('/svg/integration_google/issue?color=ffffff')
			}
			return ''
		},
		getTargetIdentifier(n) {
			if (['PullRequest', 'Issue'].includes(n.subject.type)) {
				const parts = n.subject.url.split('/')
				return '#' + parts[parts.length - 1]
			}
			return ''
		},
		getFormattedDate(n) {
			return moment(n.updated_at).format('LLL')
		},
	},
}
</script>

<style scoped lang="scss">
</style>
