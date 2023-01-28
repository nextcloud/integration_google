# Google integration in Nextcloud

**This is a fork!!** Please see [the upstream](https://github.com/MarcelRobitaille/nextcloud_google_synchronization) or [the issue that prompted this fork](https://github.com/MarcelRobitaille/nextcloud_google_synchronization/issues/77).

This fork tries to fix two problems I had with the Google integration:
1. It only syncs stuff when you click the import button. It doesn't keep the imported stuff up to date
1. It only adds new data, it doesn't delete outdated data. Anytime someone on my team would reschedule something in our Google Calendar, I would have it multiple times in my Nextcloud calendar.

I have fixed both of these, at least for the Calendar (the only integration I use).

**USE AT YOUR OWN RISK!!** This is my first Nextcloud app and it is definitely not production-ready. For instance, I blindly add a new background job without checking if it already exists. I don't do logging correctly.

🇬 Google integration allows you to automatically import your Google calendars, contacts, photos and files into Nextcloud.

## 🚀 Installation

In your Nextcloud, simply enable the Google Integration app through the Apps management.
The Google Integration app is available for Nextcloud >= 22.

## 🔧 Setup

The app needs some setup in the Google API Console in order to work.
To do this, go to Nextcloud Settings > Administration > Connected accounts and follow the instructions in the "Google integration" section.
