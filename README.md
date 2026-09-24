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
inbound_public_key:
inbound_username:
inbound_password:
```

`api_key` is the key SendGrid sends with, and Mail Send is all the permission it needs. `public_key` and `setup_api_key` are only for delivery reports, and the three `inbound_` settings are only for receiving mail; both are covered in their own sections below. Leave them empty if you are only sending.

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

Once an add-on has given you a webhook address, you get one button. Press **Set up** and this plugin creates the webhook in SendGrid with exactly the six events worth acting on, turns on Signed Event Webhook, and saves the verification key it hands back into the Verification key field here. From then on every event arriving at that address is checked against that key before anything reads it, so a stranger who guesses the address gets nothing. Pressing it again after the webhook address has changed — a new secret, or a store that lost its settings — points the webhook SendGrid already holds at the new address rather than leaving a dead one beside it.

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

## Receiving mail

SendGrid's Inbound Parse takes the mail sent to a domain you point at it and posts each message to a web address. This plugin knows how to read those posts and hands that to the Email plugin, so an add-on that receives email (a helpdesk, say) can use SendGrid without knowing anything about it. It needs Email plugin 5.3 or later, the release that added inbound mail; on an older Email plugin this plugin keeps sending exactly as before and simply does not offer a receiver.

The add-on gives you the webhook address. Then:

1. Pick the domain or subdomain that will receive the mail. A subdomain such as `reply.example.com` leaves your normal mailbox alone. It has to be authenticated in SendGrid under **Settings**, **Sender Authentication**.
2. At your DNS host, add an MX record for that name pointing to `mx.sendgrid.net`, priority 10.
3. In SendGrid, go to **Settings**, **Inbound Parse**, press **Add Host & URL**, choose the domain and paste the webhook address as the **Destination URL**.
4. Tick **POST the raw, full MIME message**. The whole message then arrives as it was sent, so the add-on can keep the original and every attachment and charset is read the same way as mail from any other source. Without it SendGrid takes the message apart first; that works too, but there is no original to keep and the attachments have to be copied out during the request.
5. Tick **Check incoming emails for spam** if you want a spam score passed along.

Messages can be up to 30 MB. SendGrid retries a post that fails with a server error for up to three days, so the add-on answers as soon as the message is stored.

### How a post is trusted

By default SendGrid signs nothing, and the long random secret in the webhook address is the whole of the protection. The add-on shows this receiver as "authenticated by secret URL" for that reason. Two things can be added:

- **Basic auth.** Put a username and password in the Destination URL (`https://name:password@example.com/…`) and the same pair in **Inbound username** and **Inbound password** here, and a post without them is refused.
- **Signatures.** SendGrid can now sign Parse posts, through a Parse *security policy* with signature verification that you create and attach to the host with SendGrid's API (`POST /v3/user/webhooks/security/policies`, then the host's `security_policy`). Paste the policy's public key into **Inbound public key**. The signature is ECDSA over the timestamp and the raw request body, checked exactly like the Event Webhook's. The catch is PHP's: it does not keep the raw body of a `multipart/form-data` post, and SendGrid always posts that way, so a signature can only be checked where PHP has been told to leave the body alone (`enable_post_data_reading = Off` for the webhook address). With a key set and no raw body, every post is refused and the log says why, so leave the key empty unless you have done that. A security policy can use OAuth instead; checking an OAuth token means calling your OAuth server, which a webhook receiver must not do, so OAuth policies are not supported.

## Credits

Thanks to the [Syfmony team](https://symfony.com) for making this plugin possible.


