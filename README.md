# Mucronix Contact Lite

[🇷🇺 Русская версия](README.ru.md)

A contact form embedded in your page. The visitor fills it in, presses Send, and the message goes
to the site owner by email. The page does not reload.

Built for **Joomla 6 with the Behaviour Backward Compatibility plugin switched off**. It uses no
`JFactory`, no `JText` and no other legacy non-namespaced classes.

- **Requirements:** Joomla 6.0 or later, PHP 8.3 or later.
- **Licence:** GNU GPL version 2 or later, see `LICENSE`.
- **Support:** support@mucronix.com

This repository contains **Lite**, the free edition. **Pro** adds Telegram, extra fields,
attachments and the full set of appearance settings:
<https://mucronix.com/products/joomla/mucronix-contact>

---

## Lite and Pro

| Feature | Lite | Pro |
|---|:-:|:-:|
| The message by email | ✓ | ✓ |
| Recipient, Subject, Intro Text, Button Text, Success Message | ✓ | ✓ |
| Additional Recipients | — | ✓ |
| Thank You Page | — | ✓ |
| Captcha (Use Global or a chosen one) + honeypot | ✓ | ✓ |
| Limit: one message in 30 seconds per session | ✓ | ✓ |
| Name / Email / Message fields: shown and required | ✓ | ✓ |
| Consent Checkbox + Policy Article + Consent Text | ✓ | ✓ |
| Attachment Field and everything that belongs to it (types, limit, the copy of the core layout file.php) | — | ✓ |
| Extra Fields + the page "Notes on extra fields" | — | ✓ |
| Telegram (the whole tab) | — | ✓ |
| Appearance: Field Markup (Bootstrap 5 / UIkit / No Added Classes) | ✓ | ✓ |
| Appearance: Own Styles (Full / Basic / None) | ✓ | ✓ |
| Appearance: Field Look, Border Colour, Corner Radius, Space Between Fields, Frame, Form Background, Form Width, Button Look, Accent Colour | — | ✓ |
| Appearance: Wrapper / Form / Field / Button Class, Custom CSS | — | ✓ |
| The page "Styling notes" | short: the CSS variables `--mcx-*` and overriding the layout only | full |
| The CSS variables `--mcx-*` in the site template | ✓ | ✓ |
| Working without JavaScript, sending by AJAX | ✓ | ✓ |
| Log of errors | ✓ | ✓ |
| Log Sent Messages | — | ✓ |
| The notice above the form for site managers (only about what the edition has) | ✓ | ✓ |
| The check for Joomla 6.0+ / PHP 8.3+ on installation | ✓ | ✓ |
| The version and the edition in the first line of the Module tab | ✓ | ✓ |
| Languages en-GB, ru-RU, uk-UA | ✓ | ✓ |

Both editions use the same Joomla module, `mod_mucronix_contact`. Pro can be installed over Lite
through the standard Joomla installer, and all form settings are preserved.

---

## Installation

Download `mod_mucronix_contact-lite-<version>.zip` from
[Releases](https://github.com/mucronix/mod_mucronix_contact/releases), install it through
**System → Install → Extensions**, then publish the module in **Content → Site Modules** and assign
it a position and menu items as for any other module.

Installation stops on Joomla below 6.0 or PHP below 8.3 and reports the reason. On a first install
Joomla does not read minimum versions from an extension manifest, only from an update server, so
the module performs the check itself.

---

## Settings

**Module** — who receives the message and what the visitor sees.

| | |
|---|---|
| Recipient | Empty uses the site email address |
| Subject | Empty uses "Message from *site name*" |
| Intro Text | Shown above the form, HTML allowed |
| Button Text | Empty uses the standard wording |
| Captcha | Which CAPTCHA protects this form. Use Global follows the site setting |
| Success Message | Shown in place of the form once the message has been sent |

There is deliberately no "form heading" setting. Joomla already gives every module a title with a
tag and a class of its own; a second heading would produce two headings in a row. If you hide the
module title and still want a heading, put it in Intro Text, which accepts HTML.

**Fields** — which of Name, Email and Message are shown and which are required, the consent checkbox,
the article containing your privacy policy and the text next to the checkbox.

**Appearance** — Field Markup, Own Styles and a button that opens the styling notes. See
[Styling](#styling).

**Advanced** — layout and module class suffix. Errors are written to
`administrator/logs/mod_mucronix_contact.php`.

---

## The Page Cache plugin

If **System — Page Cache** is switched on, exclude the page that contains this form. The plugin
serves whole cached pages to visitors who are not logged in, which causes two separate problems,
and neither produces a visible error.

**The form token is cached with the page.** Every guest after the first receives another visitor's
token, and the form refuses to send. Reloading does not help: it returns the same cached page
with the same token.

**The response replaces the form.** This problem is more serious and harder to notice. After a
submission without JavaScript the module redirects back to the same address, the browser requests
it with a GET, and that page — the one saying the message has been sent — is what gets cached. From
then on guests see a confirmation of another visitor's message instead of the form.

Use **Exclude Menu Items** or **Exclude URLs** in the plugin's settings.

The module cannot prevent this on its own: caching is disabled through an event raised long before
any module is rendered, and only a plugin can handle it. The module warns the site owner instead.
If no visitor can reach the form without logging in, none of this applies — the plugin does not
cache pages for logged-in users.

---

## Styling

### 1. The two settings

**Field Markup** adds the form classes of your template's framework. The module never removes a
class, so everything the core outputs is kept.

| Template | Choose |
|---|---|
| Cassiopeia and other Bootstrap 5 templates | Joomla (Bootstrap 5) |
| YOOtheme Pro | UIkit (YOOtheme) |
| Anything else | try both, keep the better one, or No Added Classes |

**Own Styles** sets how much the module styles: *Full* — spacing and a border around every field;
*Basic* — spacing, the consent line and error highlighting, nothing more; *None* — no module
stylesheet is loaded, and the template styles the form.

### 2. Variables in your template's CSS

Everything the module styles is controlled by CSS variables. Set them in the CSS of your template:

- **Cassiopeia** — the file `user.css`. It does not exist until you create it: **System → Site
  Templates → Cassiopeia Details and Files → New File**, choose the folder `css`, enter the name
  `user` and the file type `css`, then press **Create**. Cassiopeia loads that file automatically.
- **YOOtheme Pro** — in the customizer, **Settings → CSS**.

Write them on `#mcx-12`, where 12 is the id of the module, or on `body .mcx` for every contact
form on the site at once:

```css
body .mcx {
    --mcx-accent: #1f5aa8;
    --mcx-gap: 10px;
    --mcx-field-radius: 8px;
}
```

| Variable | Sets | Default |
|---|---|---|
| `--mcx-gap` | the space between one field and the next | `12px` |
| `--mcx-label-gap` | the space between a label and its own field | `2px` |
| `--mcx-accent` | a field in focus, the ticked checkbox | `#2f6fbf` |
| `--mcx-radius` | the corner radius of the message and notice blocks | `4px` |
| `--mcx-field-border` | the border of the fields | `#dddddd` |
| `--mcx-field-radius` | the corner radius of the fields | `4px` |
| `--mcx-form-bg` | the background behind the form | transparent |
| `--mcx-form-max` | the maximum width of the form | none |
| `--mcx-ok`, `--mcx-ok-bg` | the block shown once a message has been sent | `#27884a`, `#eaf6ee` |
| `--mcx-error`, `--mcx-error-bg` | error messages and the fields they relate to | `#c0392b`, `#fdecea` |
| `--mcx-notice`, `--mcx-notice-bg` | the notice shown only to site managers | `#b8860b`, `#fff8e5` |

`--mcx-field-border`, `--mcx-field-radius`, `--mcx-form-bg` and `--mcx-form-max` take effect only
when Own Styles is set to *Full*: they are read by the stylesheet that *Basic* and *None* do not
load.

> ⚠️ **Give `--mcx-accent` a dark colour.** It tints the consent checkbox, and the browser draws the
> tick on top of it in white. A pale accent results in white on white: a ticked checkbox that looks
> empty.

The same information, with examples, is on the page opened by the **Open the styling notes**
button. The page ships with the module, one per language, at
`media/mod_mucronix_contact/docs/styling.<language>.html`.

### 3. Overriding the layout

Copy `tmpl/default.php` to `templates/<your template>/html/mod_mucronix_contact/default.php` and
edit the copy — or let Joomla do it: **System → Site Templates → your template → Create Overrides →
Modules → mod_mucronix_contact**. Joomla picks up the copy automatically, and updates do not
overwrite it. `default_mail.php` next to it is the body of the message you receive.

> ⚠️ **This layout is more than markup.** It contains the elements that sending depends on, and most
> failures here are silent: the form still looks right, but the messages stop arriving.

Remove any of these and **the form stops sending**:

- the hidden `mcx_module_id`, which tells the module which of its instances was used;
- the hidden `mcx_page`, the address a message is reported as coming from;
- the token printed by `HTMLHelper::_('form.token')`;
- the loop that renders the fields: their names are built from the module id, and hand-written
  inputs will not match them.

Remove any of these and **AJAX sending stops silently** — the form falls back to a regular POST,
keeps working, and nothing indicates the change:

- the class `mcx` on the outer element;
- the class `mcx-form` on the `<form>`;
- the `<form>` staying inside that outer element, because the handler listens there;
- the two lines that register and load the script;
- the `Text::script(...)` lines, without which the button and the error fall back to English.

Remove any of these and **messages appear in the wrong place or not at all**:

- the `mcx-message` block, where everything after a submission is written;
- `mcx-submit` on the button, which is used to show that the form is sending;
- `mcx-field--<name>` on each field, which links an error to its field;
- `mcx-field-wrap` around a field, where that error is placed;
- `id="mcx-<module id>"` on the outer element, the anchor a submission without JavaScript returns to;
- the hidden honeypot field; removing it produces no visible change but disables that protection.

---

## Things worth knowing

- **The form works without JavaScript.** It posts normally, the server renders the errors in the
  same places the script would, and the response arrives through a redirect so that refreshing the
  page never asks whether to send the form again.
- **One message per thirty seconds per session.** A second form on the same site shares that limit.
- **Nothing is stored in the database.** No message history, no admin inbox.

---

## Updates

The module includes an update server, so Joomla offers new versions of Lite through its standard
updater. Releases and their notes are at <https://github.com/mucronix/mod_mucronix_contact/releases>,
and what changed in each version is in [CHANGELOG.md](CHANGELOG.md).

Lite is never offered an update to Pro: the two editions are updated from different addresses.

> ⚠️ **Installing Lite over Pro means losing the settings that only Pro has.** The installer warns
> about this and continues. The forms keep working with the Lite features; the Pro settings remain
> stored until the module is next saved and are discarded at that moment. To restore them, install
> Pro again before saving.
