# v1.1.1
## 09/05/2026

1. [](#bugfix)
    * **Set up now repairs a webhook whose address has changed.** A store that generated a new secret, or lost its settings, was told nothing was registered while SendGrid still held a webhook at the old address, and pressing Set up made a second one beside the dead one. Set up now recognises the store's own webhook by its endpoint and points it at the new address, with the same six events asked for and the other five turned off, and signing turned on as before.

# v1.1.0
## 09/05/2026

1. [](#new)
    * SendGrid's delivery reports are now read by this plugin, through the Email plugin's provider contract, so anything on the site that records what happened to a message — delivered, bounced, marked as spam, opened, clicked, or dropped before it ever left — can ask rather than carrying a SendGrid parser of its own. The signature is checked first, over the raw request bytes exactly as they arrived, because that is what SendGrid signs; an event that arrives with no verification key on file is refused rather than accepted, and an event type nothing acts on is skipped quietly rather than refused, which is what stops SendGrid retrying it for a week
    * A **Set up** button, so a webhook is created from the API key you already pasted in rather than from five pages of instructions. It looks for a webhook already pointed at the address and updates that one instead of making a second, asks for exactly the six events worth acting on and explicitly turns the other five off, turns on Signed Event Webhook, and saves the verification key SendGrid hands back — which it hands back once and never again. Where the key cannot be saved it is printed in the message instead, because losing it would leave a store with a signed webhook and every event refused
    * A **Verification key** field, which is the key from SendGrid's Signed Event Webhook panel. Paste it exactly as the dashboard shows it; the PEM wrapper is added for you, and a whole PEM is accepted too
    * A **Setup API Key** field, for a store that sends with a key restricted to Mail Send. A Mail Send key cannot create a webhook, and the alternative would be putting a full-access key in the field the plugin sends with. Nothing sends with this one
    * What this transport does to a message on the way out is now answered rather than guessed at: custom headers and the RFC 8058 unsubscribe pair both reach the wire, over SMTP and over the API, and SendGrid does not send headers back in a webhook at all. A screen can now say that instead of a store finding out a year later
    * What SendGrid needs a sending domain's DNS to say — the SPF host, the zone its DKIM selectors point into, the zone a custom return path points into — so a deliverability check can stop carrying a table of it
    * The custom arg a send id travels in is now named by the Email plugin rather than by this one. It is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says, and it used to be `X-KahunaCart-Send` — another product's name in a Team Grav plugin. Whatever sends the mail sets the custom arg under the same name, so the two ends cannot disagree about it.
    * A `dropped` event now says whether SendGrid refused the address or refused the message, which is the difference between a subscriber who is gone and one who was on the list the day the store ran out of allowance. `Unsubscribed Address`, `Bounced Address`, `Spam Reporting Address` and `Invalid` are the address, and a store may treat them as permanent. `Invalid SMTPAPI header`, `Spam Content` and `Recipient List over Package Quota` are the message, and touch nobody — a broken header is the sender's, spam content is this message's, and a package quota is the merchant's billing plan. A reason nobody has seen before is read as the message
    * A test suite, run with `tests/vendor/bin/phpunit` after `composer install` inside `tests/`. It installs into `tests/vendor` from its own `tests/composer.json`, so the `vendor` directory the plugin ships stays free of development packages. Point `EMAIL_PLUGIN_ROOT` at your Email plugin checkout if it is not the sibling folder

# v1.0.2
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags

# v1.0.1
## 03/26/2025

1. [](#improve)
   * Support for PHP 8.4+
   * Updated to latest vendor libs
1. [](#bugfix)
   * fix null config bug

# v1.0.0
## 05/09/2023

1. [](#new)
   * Initial public release

# v1.0.0-rc.3
##  10/12/2022

1. [](#bugfix)
   * default to empty string in config values are null

# v1.0.0-rc.2
##  10/05/2022

1. [](#bugfix)
   * Set `email` plugin dependency to `4.0.0-rc.1`

# v1.0.0-rc.1
##  10/05/2022

1. [](#new)
    * ChangeLog started...
