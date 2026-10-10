## The Mail page {#action-mail-settings}

The "Mail" page (Admin menu) decides **how your shop sends email**: order confirmations today, other messages later. Nothing on it can stop a customer from placing an order. If email is misconfigured, the order still goes through and the email is retried or reported.

The page has five parts:

- **Current state**: one plain sentence, for example "Emails are only written to the log" (nothing is really sent) or "SMTP configured".
- **How mail is sent**: use the server's own setting, or type the SMTP details of your mail provider.
- **Sender**: the addresses customers see.
- **Test email**: sends one real message so you know it works.
- **Check DNS** and **Sending queue**: two advisory helpers described below.

## What SMTP is {#action-mail-smtp}

SMTP is the standard way a program hands an email to a mail server. You do not run that server yourself: a provider does, and gives you five things: a **server name**, a **port**, the **encryption** type, a **username** and a **password**. Type them into the page and press Save.

The password is stored encrypted and is **never shown again**. When one is saved the field shows "(saved)". Leave it empty to keep it, type a new one to replace it, or use "Remove saved password". If the application key of the server was changed, the saved password becomes unreadable and the page asks you to enter it again.

## Which provider {#action-mail-providers}

Any provider that gives you SMTP details works. A short comparison of the usual choices:

| | Brevo | Resend | Your own mailbox |
|---|---|---|---|
| Account | Yes, free tier | Yes, free tier | The mail hosting you already pay for |
| You get | SMTP server, login, key | SMTP server and an API key used as password | Server, your address and its password |
| Domain setup | Add the records they show (SPF, DKIM) | Add the records they show (SPF, DKIM) | Usually already set up for that domain |
| Good for | Orders and later newsletters | Orders, simple setup | Very small shops; hosting limits are low |

Whatever you choose, the provider's own page lists the exact records to add to your domain. Copy them exactly.

## Why not a free address as the sender {#action-mail-sender}

Do not use `yourshop@gmail.com` (or another free address) as the sender. Gmail and similar services tell receiving servers that **only they** may send for their domain, so mail you send "as gmail" from your shop is treated as forged and goes to spam or is rejected. Use an address on **your own domain**, for example `orders@yourshop.com`.

Two identities are kept:

- **Orders** (`orders@yourdomain`): order confirmations and other messages about an order.
- **News** (`news@yourdomain`): reminders and newsletters, when they exist. You can later use a separate sub-domain such as `mail.yourdomain` for these, so a problem with newsletters cannot affect order emails. That needs one more DNS setup, so start without it.

The **reply-to** address is where customer answers go, for example `info@yourdomain`. It should be a mailbox someone reads.

The sender name is plain text, at most 80 characters. Line breaks and hidden control characters are refused.

When you have set up your domain, tick "I own this domain and have set up its DNS records". Until then the page says "sender not verified".

## SPF, DKIM and DMARC in plain words {#action-mail-dns}

Three DNS records tell the world that your email is really yours. DNS is the address book of the internet; you edit it where you bought the domain. Without them, big mailboxes are likely to put your messages in spam.

- **SPF**: a list of servers allowed to send for your domain. It is a TXT record on the domain itself and looks like `v=spf1 include:PROVIDER-NAME ~all`. Your provider tells you the `include` part.
- **DKIM**: a digital signature added to every message. The provider gives you a record to publish, a TXT or CNAME at `SELECTOR._domainkey.yourdomain`. The first part, the **selector**, is a name the provider chooses.
- **DMARC**: tells receivers what to do when SPF/DKIM fail and where to send reports. It is a TXT record at `_dmarc.yourdomain` and looks like `v=DMARC1; p=none; rua=mailto:REPORT-ADDRESS`. **Start with `p=none`** (only watch). After some weeks of clean reports you may tighten it, with your provider's or adviser's guidance.

**The domain in the sender address must be the domain whose DNS you edit.** Authenticating `shop-a.com` does nothing for mail sent from `shop-b.com`.

DNS changes can take from minutes to a day to be visible everywhere.

## The "Check DNS" button {#action-mail-check-dns}

The button looks up the SPF and DMARC records of your sender domain and, if you type the selector, the DKIM record. If you type the **provider include**, it also checks that the SPF record contains it.

It is advice only and **never blocks sending**. A result of "not seen yet" does not mean something is broken: DNS is cached and may not have reached this server yet. Wait a while and try again. Only valid host names are looked up; anything else is refused without a lookup.

## The test email {#action-mail-test}

"Send test email" sends one message immediately using the current saved settings, so save first. By default it goes to your own address; another address is allowed **once per minute**. If the mail server refuses the message, you see its real reason (with passwords and links removed), for example "authentication failed", so you can fix the credentials.

Test messages are not part of the order emails and never appear as failed orders.

## The sending queue and the worker {#action-mail-queue}

Order emails are not sent while the customer waits. They are put in a queue and sent a moment later by a **background worker** that must always be running on the server. The page shows how many mail messages are waiting and how old the oldest is. If numbers grow and the age keeps increasing, the worker is stopped or does not serve the mail queues.

The worker must be started with the mail queues listed, in this order (the first is served first):

`php artisan queue:work database --queue=mail-transactional,default,mail-marketing --sleep=3 --tries=3 --max-time=3600`

Without the `--queue` part the worker serves only the default queue and order emails wait forever. After every update of the shop, run `php artisan queue:restart` so the worker loads the new code. Your server administrator or hosting support can do this.
