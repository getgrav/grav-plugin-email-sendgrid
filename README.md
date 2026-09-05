# Email Sendgrid Plugin

The **Email Sendgrid** Plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It lets the Email plugin send through SendGrid, over their API or over SMTP, and it tells the rest of your site what SendGrid knows about itself — how to read its delivery reports, how to set one up from the API key you already pasted in, and what its DNS has to say.

## Installation

Installing the Email Sendgrid plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install email-sendgrid

This will install the Email Sendgrid plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/email-sendgrid`.

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/email-sendgrid/email-sendgrid.yaml` to `user/config/plugins/email-sendgrid.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
transport: api
api_key:
public_key:
setup_api_key:
```

`api_key` is the key SendGrid sends with, and Mail Send is all the permission it needs. `public_key` and `setup_api_key` are only for delivery reports and are covered in their own section below; leave them empty if you are only sending.

Note that if you use the Admin Plugin, a file with your configuration named email-sendgrid.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

The **transport** can either be `api` (recommended) or `smtp`.

Once the options are set, all other configuration regarding email should be done in the main `email` plugin.  You just need to set the engine in the `email.yaml` configuration:

```yaml
mailer:
  engine: sendgrid
```

A default `from:` and `to:` address is also required.

## Delivery reports

SendGrid can tell your site what happened to every message it sent — delivered, bounced, marked as spam, opened, clicked, or dropped before it ever left. This plugin knows how to read those reports and how to set them up, and it hands both to the Email plugin so that anything on your site which records them can just ask. You need an add-on that actually wants them, such as the KahunaCart Newsletter; on its own this plugin only makes the answers available.

Once an add-on has given you a webhook address, you get one button. Press **Set up** and this plugin creates the webhook in SendGrid with exactly the six events worth acting on, turns on Signed Event Webhook, and saves the verification key it hands back into the Verification key field here. From then on every event arriving at that address is checked against that key before anything reads it, so a stranger who guesses the address gets nothing.

The one thing the button needs is an API key allowed to manage webhooks. SendGrid's own advice is to send with a key restricted to Mail Send, and a Mail Send key cannot create a webhook. If that is what you have, make a second key in SendGrid with full access to **Webhook** — it is one of the permission groups in the key's own Restricted Access list under Settings, then API Keys — and paste it into **Setup API Key**. Nothing sends with it.

### Doing it by hand

If you would rather not give this plugin a second key, all of it can be done in the dashboard:

1. Go to **Settings**, then **Mail Settings**, then **Event Webhooks**, and add a webhook with the address your add-on gave you.
2. Tick **Delivered**, **Bounced**, **Dropped**, **Spam Reports**, **Opened** and **Clicked**. Leave **Processed**, **Deferred** and the three unsubscribe events off — nothing acts on them, and `processed` alone is one request per message you send.
3. Turn on **Signed Event Webhook** and press **Save**. The verification key does not exist until that first save.
4. Copy the key it then shows into the **Verification key** field here, exactly as SendGrid prints it. The PEM wrapper is added for you.

### What arrives, and what it can be tied to

A bounce is hard when SendGrid calls it `bounce` and soft when it calls it `blocked`. A `dropped` is SendGrid refusing to send at all, because the address is already on its own suppression list or bounced before or reported spam; that is reported as its own thing rather than as a bounce, and most stores will want to treat it like one.

Events are tied back to the message they came from by `Message-ID`, which SendGrid echoes as `smtp-id`. That is the path to rely on, because SendGrid documents two gaps in its own ids: `sg_message_id` is missing from delayed bounces, and custom arguments do not attach to bounce events carrying a `Return-Path`. SendGrid does not send message headers back in a webhook at all — what it sends back is the message's custom args, as top-level fields on the event — so a store that wants a second correlation path sets a custom arg rather than a header, which over SMTP means the `unique_args` map inside `X-SMTPAPI`. The name to use is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says; whatever is sending the mail already knows it.

## Credits

Thanks to the [Syfmony team](https://symfony.com) for making this plugin possible.


