# Mucronix Contact

A contact form that sits in the page. The visitor fills it in, presses Send, and the message goes
to the site owner by email and, if you want it to, to a Telegram chat. The page does not reload.

Built for **Joomla 6 with the Behaviour Backward Compatibility plugin switched off**. That is the
point of it: no `JFactory`, no `JText`, nothing from the old namespaceless world.

- **Requirements:** Joomla 6.0 or later, PHP 8.3 or later.
- **Licence:** GNU GPL version 2 or later, see `LICENSE`.
- **Support:** support@mucronix.com

---

## Installation

Install the ZIP through **System → Install → Extensions**, then publish the module in
**Content → Site Modules** and give it a position and a menu assignment like any other module.

The install refuses to run on Joomla below 6.0 or PHP below 8.3 and says why. Joomla does not read
minimum versions out of an extension manifest on a first install, only out of an update server, so
the module checks for itself.

---

## Settings

**Basic** — who the message goes to and what the visitor reads.

| | |
|---|---|
| Recipient | Empty uses the site email address |
| Additional Recipients | One address per line |
| Subject | Empty uses "Message from *site name*" |
| Intro Text | Shown above the form, HTML allowed |
| Button Text | Empty uses the standard wording |
| Captcha | Which captcha guards this form. Use Global follows the site setting |
| Success Message | Shown in place of the form once the message has gone |
| Thank You Page | Sends the visitor to a page of your own instead |

There is deliberately no "form heading" setting. Joomla already gives every module a title with a
tag and a class of its own; a second heading would print two in a row. If you hide the module title
and still want a heading, put it in Intro Text, which takes HTML.

**Thank You Page** holds a menu item, not an address, so renaming that page's alias cannot quietly
turn the redirect into a 404. When it is set the visitor is taken there the moment the message goes
out, with no message shown first — which is what an analytics goal on that page needs. Success
Message is then never seen.

**Fields** — which fields are shown and which are required, the consent checkbox and its policy
article, and the attachment field with its own list of accepted extensions and size limit.

**Extra Fields** — see below.

**Telegram** — see below.

**Appearance** — whether to load the module stylesheet, and three places to hang your template's
own classes: around the form, on the form, and on the send button.

**Advanced** — layout, module class suffix, and **Log Sent Messages**. Errors always go to
`administrator/logs/mod_mucronix_contact.php`; successful sends only when you switch this on. Leave
it off in normal use: that log grows without limit and records who wrote and when.

---

## Extra fields

The **Extra Fields** tab takes fields written in the Joomla form format. They appear in front of the
captcha and are written into the message:

```xml
<field name="phone" type="tel" label="Phone" />
<field name="topic" type="list" label="Topic">
    <option value="sales">Sales</option>
    <option value="support">Support</option>
</field>
```

Accepted types: `text`, `textarea`, `email`, `tel`, `url`, `number`, `list`, `radio`, `checkbox`,
`checkboxes`, `calendar`. A name has to start with a letter and may hold letters, digits and
underscores only. Names the module uses itself are refused, and so are a handful of attributes that
could point a field at a file of its own or push one of the module's own fields off the page.

Anything skipped is named in a notice above the form, with the reason. That notice is shown only to
people who can manage modules — visitors see the form as usual.

A `radio` field must be written with its options. The core layout for a switcher returns nothing at
all when the option list is empty, and you get a label with no control under it.

---

## Telegram

This is the part that goes wrong most often, and almost never in the code.

### 1. Make a bot

Open **@BotFather** in Telegram and send `/newbot`. It asks for a display name, then for a username
that has to end in `bot`. It answers with a token that looks like this:

```
123456789:AAHk4pTaSyZ1qR7vXnB2wLmE5cD8fG0hIjK
```

Lost it? It is not gone: **@BotFather → /mybots → your bot → API Token**. You do not need a new bot.

### 2. About the angle brackets

Instructions everywhere write the Bot API address like this:

```
https://api.telegram.org/bot<token>/getUpdates
```

The `< >` marks a place to fill in. **They are not part of the address.** Pasting the token with the
brackets still around it gives a bare `404 Not Found` with nothing to explain it. With the token
above the real address is:

```
https://api.telegram.org/bot123456789:AAHk4pTaSyZ1qR7vXnB2wLmE5cD8fG0hIjK/getUpdates
```

Note that `bot` stays joined to the digits. There is no slash and no space between them.

### 3. Find the chat id

Two ways, either will do:

- write to **@userinfobot** and it answers with your own id straight away;
- write any message to *your* bot, then open the `getUpdates` address above in a browser and look
  for `"chat":{"id":123456789`.

If `getUpdates` answers `{"ok":true,"result":[]}`, nobody has written to the bot yet.

### 4. A bot cannot write first

Telegram will not let a bot open a conversation. Until you — or somebody in the group — have sent
the bot a message, delivery is refused and `getUpdates` stays empty. The symptom is exactly what it
sounds like: nothing arrives, and nothing looks broken.

### 5. Groups

Add the bot to the group first. A group id is **negative** and usually starts `-100`. The minus sign
is part of the number: write it in.

### 6. Then fill the tab in

Switch **Send to Telegram** on, paste the token and the chat id. Turn on **Send the Attachment Too**
if you want files in the chat as well — images go as a photo, everything else as a file, and
Telegram accepts at most 10 MB for a photo and 50 MB for a file.

The email always goes first and is never held up by the chat. If Telegram refuses or does not
answer, the visitor still sees the message go through and the reason is written to the module log.
So check the log when the chat stays quiet.

> ⚠️ **The bot token is stored in the database as plain text** and stands in the page source of the
> module settings for anyone who can open this module for editing. Joomla keeps its own SMTP and
> database passwords the same way; there is no reversible encryption for settings in the core, and
> encrypting it here would only mean putting the key next to the ciphertext. Give the bot access to
> the one chat it needs, and revoke the token in @BotFather if it ever gets out.

---

## The Page Cache plugin

If **System — Page Cache** is switched on, exclude the page carrying this form. The plugin hands
whole stored pages to visitors who are not logged in, and two separate things then go wrong, neither
of which shows up anywhere.

**The form token is stored with the page.** Every guest after the first is handed somebody else's
token, and the form refuses to send. Reloading does not help: it brings back the same stored page
with the same token.

**The thank-you page replaces the form.** This one is worse and much less obvious. After a
submission without JavaScript the module redirects back to the same address, the browser asks for it
with a GET, and that page — the one saying the message has been sent — is what goes into the store.
From then on guests are shown a thank-you for somebody else's message instead of the form.

Use **Exclude Menu Items** or **Exclude URLs** in the plugin's settings.

The module cannot do anything about this on its own: caching is called off through an event raised
long before any module is drawn, and only a plugin may answer it. It warns the site owner instead.
If no visitor can reach the form without logging in, none of this applies — the plugin leaves pages
for logged-in users alone.

---

## Styling

The module ships a small stylesheet that sets the spacing between fields, the width of the inputs,
the consent checkbox, the highlighting of errors and the message block. It draws no card, no
background, no border and no shadow: that is your template's job, through **Wrapper Class**. For a
Bootstrap template `card p-4 shadow-sm` works; on YOOtheme
`uk-card uk-card-default uk-box-shadow-medium`.

Colours and the corner radius are variables, so one line in your own CSS changes them all:

```css
.mcx {
    --mcx-accent: #1f5aa8;
    --mcx-radius: 8px;
}
```

> ⚠️ **Give `--mcx-accent` a dark colour.** It tints the consent checkbox through `accent-color`,
> which paints the box while the browser draws the tick on top of it in white, and the tick cannot
> be restyled. A pale accent gives you white on white: a ticked checkbox that looks empty.
>
> On a template that draws checkboxes itself — Bootstrap does, Cassiopeia among them, with
> `appearance: none` — this variable does not reach the checkbox at all and its look stays the
> template's. Every other colour works everywhere.

Switch the stylesheet off entirely with **Load Module CSS** if your template dresses its forms.

---

## Things worth knowing

- **The form works without JavaScript.** It posts normally, the server draws the errors in the same
  places the script would, and the answer arrives through a redirect so that refreshing the page
  never asks whether to send the form again.
- **A file field is never refilled by a browser.** If anything else in the form needs correcting,
  the visitor has to choose the file again. The field says so.
- **One message per thirty seconds per session.** A second form on the same site shares that wait.
- **The attachment field prints the limit that actually applies**, not the server's. It does that by
  carrying a copy of one core layout, `layouts/mucronix/form/field/file.php`. Two consequences: the
  copy wants checking against the original when Joomla updates, and a `joomla.form.field.file`
  override from your template does not apply to this one field.
- **Nothing is stored in the database.** No message history, no admin inbox.

---

## Updates

The module carries an update server, so Joomla offers new versions in the usual place. Releases and
their notes are at <https://github.com/mucronix/mod_mucronix_contact/releases>.

---

## Credits

The idea owes something to **Wedal Joomla Callback**, which solved the same problem for older
Joomla. No code, markup, language string or stylesheet was taken from it — this module is written
from scratch and carries its own licence.
