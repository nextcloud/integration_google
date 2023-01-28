# Google Synchronization

**This is a fork of the [Google Integration](integration_google) app**

**Use at your own risk. This app is still in early development. Users are effectively beta testers.**

If all you need to do is import all of your data from Google once and permanently migrate to Nextcloud (lucky you),
you should use the [Google Integration](integration_google) app.

However, if you're like me, you're part of a team or group that has shared a Google Calendar with you,
and you would like to keep it up to date with your Nextcloud calendar.
That's exactly what this app does.

This is a fork of [Google Integration](integration_google)
that creates a background task that will periodically import all changes from Google Calendar to your Nextcloud calendar.
As such, all functionality of [Google Integration](integration_google)
is still implemented, so you can still import Contacts, Photos, Drive manually.
However, currently, **only Google Calendar background synchronization is supported**.
Please let me know if you would like to continuously synchronize other services.

This is a one-way synchronization.
Events from Google Calendar are imported into Nextcloud,
but events from Nextcloud are not sent to Google.

This App supports:
1. **New events**: Adding a new event in Google Calendar will create a new event in Nextcloud Calendar
1. **Modified events**: Modifying an event in Google Calendar will modify the event in Nextcloud Calendar
1. **Deleted events**: Well, you get it by now
1. **Calendars you own**
1. **Calendars that have been shared with you**


[integration_google]: https://github.com/nextcloud/integration_google

## 🚀 Installation

In your Nextcloud, simply enable the Google Integration app through the Apps management.
The Google Integration app is available for Nextcloud >= 22.

## 🔧 Setup

The app needs some setup in the Google API Console in order to work.
To do this, go to Nextcloud Settings > Administration > Connected accounts and follow the instructions in the "Google integration" section.

## Development guide

1. Setup Nextcloud development environment (such as [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev))
1. Install the files for this app in the development environment (I like to modify the `nextcloud-docker-dev` `docker-compose.yml` file and add a volume like this: `- ../google_synchronization:/var/www/html/apps/google_synchronization:ro`. Please read that project's README for alternative methods.)
1. Install PHP dependencies (install [Composer](https://getcomposer.org/), run `composer install`)
1. Install Node dependencies (install [Node.js](https://nodejs.org/en/), run `npm install`)
1. Build JavaScript bundle: `npm run dev` or `npm run watch`
1. Enable the app. Go to the apps page in your development version of Nextcloud, find "Google Synchronization", and click "Enable"
