OC.L10N.register(
    "integration_google",
    {
    "No logged in user" : "No logged in user",
    "Missing refresh token in Google response." : "Missing refresh token in Google response.",
    "Error getting OAuth access token." : "خطا در دریافت نشانه دسترسی OAuth.",
    "Error during OAuth exchanges" : "خطا در هنگام تبادل OAuth",
    "Google" : "Google",
    "_%n photo was imported from Google._::_%n photos were imported from Google._" : ["%n photo was imported from Google.","%n photos were imported from Google."],
    "_%n file was imported from Google Drive._::_%n files were imported from Google Drive._" : ["%n file was imported from Google Drive.","%n files were imported from Google Drive."],
    "OAuth access token refused" : "نشانه دسترسی OAuth رد شد",
    "Bad credentials" : "اعتبارنامه بد",
    "Google Calendar import" : "Google Calendar import",
    "Private event" : "Private event",
    "Connected accounts" : "حساب‌های متصل",
    "Data migration" : "Data migration",
    "Google integration" : "Google integration",
    "Import Google data into Nextcloud" : "Import Google data into Nextcloud",
    "Google integration allows you to automatically migrate your Google calendars, contacts, photos and files into Nextcloud." : "Google integration allows you to automatically migrate your Google calendars, contacts, photos and files into Nextcloud.",
    "If you want to allow your Nextcloud users to authenticate to Google, create an OAuth application in your Google settings." : "If you want to allow your Nextcloud users to authenticate to Google, create an OAuth application in your Google settings.",
    "Google API settings" : "Google API settings",
    "Go to \"APIs & Services\" => \"Credentials\" and click on \"+ CREATE CREDENTIALS\" -> \"OAuth client ID\"." : "Go to \"APIs & Services\" => \"Credentials\" and click on \"+ CREATE CREDENTIALS\" -> \"OAuth client ID\".",
    "Set the \"Application type\" to \"Web application\" and give a name to the application." : "Set the \"Application type\" to \"Web application\" and give a name to the application.",
    "Make sure you set one \"Authorized redirect URI\" to" : "Make sure you set one \"Authorized redirect URI\" to",
    "Put the \"Client ID\" and \"Client secret\" below." : "Put the \"Client ID\" and \"Client secret\" below.",
    "Finally, go to \"APIs & Services\" => \"Library\" and add the following APIs: \"Google Drive API\", \"Google Calendar API\", \"People API\" and \"Photos Library API\"." : "Finally, go to \"APIs & Services\" => \"Library\" and add the following APIs: \"Google Drive API\", \"Google Calendar API\", \"People API\" and \"Photos Library API\".",
    "Your Nextcloud users will then see a \"Connect to Google\" button in their personal settings." : "Your Nextcloud users will then see a \"Connect to Google\" button in their personal settings.",
    "Client ID" : "شناسه مشتری",
    "Client ID of your Google application" : "Client ID of your Google application",
    "Client secret" : "رمز مشتری",
    "Client secret of your Google application" : "Client secret of your Google application",
    "Use a pop-up to authenticate" : "Use a pop-up to authenticate",
    "Google admin options saved" : "Google admin options saved",
    "Failed to save Google admin options" : "Failed to save Google admin options",
    "Google data migration" : "Google data migration",
    "No Google OAuth app configured. Ask your Nextcloud administrator to configure Google connected accounts admin section." : "No Google OAuth app configured. Ask your Nextcloud administrator to configure Google connected accounts admin section.",
    "Authentication" : "احراز هویت",
    "Sign in with Google" : "Sign in with Google",
    "Connected as {user}" : "متصل به عنوان {user}",
    "Disconnect from Google" : "Disconnect from Google",
    "Contacts" : "مخاطبین",
    "{amount} Google contacts" : "{amount} Google contacts",
    "Import Google Contacts in Nextcloud" : "Import Google Contacts in Nextcloud",
    "Choose where to import the contacts" : "Choose where to import the contacts",
    "New address book" : "New address book",
    "address book name" : "address book name",
    "Import in \"{name}\" address book" : "Import in \"{name}\" address book",
    "Calendars" : "تقویم‌ها",
    "Import calendar" : "Import calendar",
    "Photos" : "عکس ها",
    "Ignore shared albums" : "Ignore shared albums",
    "Warning: Google does not provide location data in imported photos." : "Warning: Google does not provide location data in imported photos.",
    "Import directory" : "Import directory",
    "Import Google photos" : "Import Google photos",
    "Your Google photo collection size is estimated to be bigger than your remaining space left ({formSpace})" : "Your Google photo collection size is estimated to be bigger than your remaining space left ({formSpace})",
    "Cancel photo import" : "Cancel photo import",
    "Drive" : "راندن",
    "Ignore shared files" : "Ignore shared files",
    "Google documents import format" : "Google documents import format",
    "Your Google Drive ({formSize} + {formSharedSize} shared with you)" : "Your Google Drive ({formSize} + {formSharedSize} shared with you)",
    "Your Google Drive ({formSize})" : "Your Google Drive ({formSize})",
    "Import Google Drive files" : "Import Google Drive files",
    "Your Google Drive is bigger than your remaining space left ({formSpace})" : "Your Google Drive is bigger than your remaining space left ({formSpace})",
    "Cancel Google Drive import" : "Cancel Google Drive import",
    "Photo import background process will begin soon." : "Photo import background process will begin soon.",
    "Last photo import job at {date}" : "Last photo import job at {date}",
    "You can close this page. You will be notified when it finishes." : "You can close this page. You will be notified when it finishes.",
    "Google Drive background import process will begin soon." : "Google Drive background import process will begin soon.",
    "Last Google Drive import job at {date}" : "Last Google Drive import job at {date}",
    "Successfully connected to Google!" : "Successfully connected to Google!",
    "Google connection error:" : "Google connection error:",
    "Google options saved" : "Google options saved",
    "Failed to save Google options" : "Failed to save Google options",
    "Failed to save Google OAuth state" : "Failed to save Google OAuth state",
    "Failed to get Google Drive information" : "Failed to get Google Drive information",
    "Failed to get calendar list" : "Failed to get calendar list",
    "Failed to get number of Google photos" : "Failed to get number of Google photos",
    "Failed to get number of Google contacts" : "Failed to get number of Google contacts",
    "Failed to get address book list" : "Failed to get address book list",
    "Failed to import Google calendar" : "Failed to import Google calendar",
    "Starting importing photos in {targetPath} directory" : "Starting importing photos in {targetPath} directory",
    "Failed to start importing Google photos" : "Failed to start importing Google photos",
    "Starting importing files in {targetPath} directory" : "Starting importing files in {targetPath} directory",
    "Failed to start importing Google Drive" : "Failed to start importing Google Drive",
    "Choose where to write imported files" : "Choose where to write imported files",
    "Choose where to write imported photos" : "Choose where to write imported photos",
    "_>{nbPhotos} Google photo (>{formSize})_::_>{nbPhotos} Google photos (>{formSize})_" : [">{nbPhotos} Google photo (>{formSize})",">{nbPhotos} Google photos (>{formSize})"],
    "_{amount} photo imported_::_{amount} photos imported_" : ["{amount} photo imported","{amount} photos imported"],
    "_{amount} file imported ({progress}%)_::_{amount} files imported ({progress}%)_" : ["{amount} file imported ({progress}%)","{amount} files imported ({progress}%)"],
    "_{nbSeen} Google contact seen. {nbAdded} added, {nbUpdated} updated in {name}_::_{nbSeen} Google contacts seen. {nbAdded} added, {nbUpdated} updated in {name}_" : ["{nbSeen} Google contact seen. {nbAdded} added, {nbUpdated} updated in {name}","{nbSeen} Google contacts seen. {nbAdded} added, {nbUpdated} updated in {name}"],
    "_{total} event successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)_::_{total} events successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)_" : ["{total} event successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)","{total} events successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)"]
},
"nplurals=2; plural=(n > 1);");
