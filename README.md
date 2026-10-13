# Mucronix Contact Lite

[🇷🇺 Русская версия](README.ru.md)

A contact form that sits in the page. The visitor fills it in, presses Send, and the message goes
to the site owner by email. The page does not reload.

Built for **Joomla 6 with the Behaviour Backward Compatibility plugin switched off**. That is the
point of it: no `JFactory`, no `JText`, nothing from the old namespaceless world.

- **Requirements:** Joomla 6.0 or later, PHP 8.3 or later.
- **Licence:** GNU GPL version 2 or later, see `LICENSE`.
- **Support:** support@mucronix.com

This repository holds **Lite**, the free edition. **Pro** adds Telegram, extra fields, attachments
and the full set of appearance settings:
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

Both editions are one module under one name, `mod_mucronix_contact`. Pro is installed over Lite the
ordinary way, and the settings of every form stay where they are.

---

## Installation

Download `mod_mucronix_contact-lite-<version>.zip` from
[Releases](https://github.com/mucronix/mod_mucronix_contact/releases), install it through
**System → Install → Extensions**, then publish the module in **Content → Site Modules** and give it
a position and a menu assignment like any other module.

The install refuses to run on Joomla below 6.0 or PHP below 8.3 and says why. Joomla does not read
minimum versions out of an extension manifest on a first install, only out of an update server, so
the module checks for itself.

---

## Settings

**Module** — who the message goes to and what the visitor reads.

| | |
|---|---|
| Recipient | Empty uses the site email address |
| Subject | Empty uses "Message from *site name*" |
| Intro Text | Shown above the form, HTML allowed |
| Button Text | Empty uses the standard wording |
| Captcha | Which captcha guards this form. Use Global follows the site setting |
| Success Message | Shown in place of the form once the message has gone |

There is deliberately no "form heading" setting. Joomla already gives every module a title with a
tag and a class of its own; a second heading would print two in a row. If you hide the module title
and still want a heading, put it in Intro Text, which takes HTML.

**Fields** — which of Name, Email and Message are shown and which are required, the consent checkbox,
the article holding your privacy policy and the wording beside the checkbox.

**Appearance** — Field Markup, Own Styles and a button that opens the styling notes. See
[Styling](#styling).

**Advanced** — layout and module class suffix. Errors are written to
`administrator/logs/mod_mucronix_contact.php`.

---

## The Page Cache plugin

If **System — Page Cache** is switched on, exclude the page carrying this form. The plugin hands
whole stored pages to visitors who are not logged in, and two separate things then go wrong, neither
of which shows up anywhere.

**The form token is stored with the page.** Every guest after the first is handed somebody else's
token, and the form refuses to send. Reloading does not help: it brings back the same stored page
with the same token.

**The answer replaces the form.** This one is worse and much less obvious. After a submission
without JavaScript the module redirects back to the same address, the browser asks for it with a
GET, and that page — the one saying the message has been sent — is what goes into the store. From
then on guests are shown a confirmation of somebody else's message instead of the form.

Use **Exclude Menu Items** or **Exclude URLs** in the plugin's settings.

The module cannot do anything about this on its own: caching is called off through an event raised
long before any module is drawn, and only a plugin may answer it. It warns the site owner instead.
If no visitor can reach the form without logging in, none of this applies — the plugin leaves pages
for logged-in users alone.

---

## Styling

### 1. The two settings

**Field Markup** adds the form classes of your template's framework. The module never takes a class
away, so whatever the core prints stays.

| Template | Choose |
|---|---|
| Cassiopeia and other Bootstrap 5 templates | Joomla (Bootstrap 5) |
| YOOtheme Pro | UIkit (YOOtheme) |
| Anything else | try both, keep the better one, or No Added Classes |

**Own Styles** says how much the module draws: *Full* — spacing and a border round every field;
*Basic* — spacing, the consent line and error highlighting, nothing more; *None* — not one stylesheet
of the module, the template dresses the form.

### 2. Variables in your template's CSS

Everything the module draws is held in variables. Set them in the CSS of your template:

- **Cassiopeia** — the file `user.css`. It does not exist until you create it: **System → Site
  Templates → Cassiopeia Details and Files → New File**, choose the folder `css`, give the name
  `user` and the file type `css`, then press **Create**. Cassiopeia loads that file by itself.
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
| `--mcx-gap` | the air between one field and the next | `12px` |
| `--mcx-label-gap` | the air between a label and its own field | `2px` |
| `--mcx-accent` | a field in focus, the ticked checkbox | `#2f6fbf` |
| `--mcx-radius` | the corner radius of the message and notice blocks | `4px` |
| `--mcx-field-border` | the border of the fields | `#dddddd` |
| `--mcx-field-radius` | the corner radius of the fields | `4px` |
| `--mcx-form-bg` | the background behind the form | transparent |
| `--mcx-form-max` | the maximum width of the form | none |
| `--mcx-ok`, `--mcx-ok-bg` | the block shown once a message has gone | `#27884a`, `#eaf6ee` |
| `--mcx-error`, `--mcx-error-bg` | refusals, and the fields they belong to | `#c0392b`, `#fdecea` |
| `--mcx-notice`, `--mcx-notice-bg` | the notice only site managers are shown | `#b8860b`, `#fff8e5` |

`--mcx-field-border`, `--mcx-field-radius`, `--mcx-form-bg` and `--mcx-form-max` only ever do
anything with Own Styles on *Full*: they are read by the stylesheet that *Basic* and *None* leave
out.

> ⚠️ **Give `--mcx-accent` a dark colour.** It tints the consent checkbox, and the browser draws the
> tick on top of it in white. A pale accent gives you white on white: a ticked checkbox that looks
> empty.

The same is told, with examples, on the page the **Open the styling notes** button opens. It ships
with the module, one per language, at `media/mod_mucronix_contact/docs/styling.<language>.html`.

### 3. Overriding the layout

Copy `tmpl/default.php` to `templates/<your template>/html/mod_mucronix_contact/default.php` and
edit the copy — or let Joomla do it: **System → Site Templates → your template → Create Overrides →
Modules → mod_mucronix_contact**. Joomla finds the copy by itself, and updates leave it alone.
`default_mail.php` beside it is the body of the message you receive.

> ⚠️ **This layout is more than markup.** It carries the wiring the sending runs on, and most of
> what can break here breaks quietly: the form still looks right, and only the messages stop
> arriving.

Drop any of these and **the form stops sending**:

- the hidden `mcx_module_id`, which tells the module which of its instances was used;
- the hidden `mcx_page`, the address a message is reported as coming from;
- the token printed by `HTMLHelper::_('form.token')`;
- the loop that renders the fields: their names are built from the module id, and inputs written
  out by hand will not answer to them.

Drop any of these and **the AJAX layer dies without a sound** — the form falls back to a plain post,
goes on working, and nobody notices:

- the class `mcx` on the outer element;
- the class `mcx-form` on the `<form>`;
- the `<form>` staying inside that outer element, because the handler listens there;
- the two lines that register and load the script;
- the `Text::script(...)` lines, without which the button and the error fall back to English.

Drop any of these and **messages land in the wrong place, or nowhere**:

- the `mcx-message` block, where everything after a submission is written;
- `mcx-submit` on the button, which is how it says it is sending;
- `mcx-field--<name>` on each field, which is how an error finds the field it belongs to;
- `mcx-field-wrap` around a field, which is where that error is put;
- `id="mcx-<module id>"` on the outer element, the anchor a submission without JavaScript returns to;
- the hidden trap field, whose loss shows nowhere and costs the protection.

---

## Things worth knowing

- **The form works without JavaScript.** It posts normally, the server draws the errors in the same
  places the script would, and the answer arrives through a redirect so that refreshing the page
  never asks whether to send the form again.
- **One message per thirty seconds per session.** A second form on the same site shares that wait.
- **Nothing is stored in the database.** No message history, no admin inbox.

---

## Updates

The module carries an update server, so Joomla offers new versions of Lite in the usual place.
Releases and their notes are at <https://github.com/mucronix/mod_mucronix_contact/releases>, and
what changed in each version is in [CHANGELOG.md](CHANGELOG.md).

Lite is never offered an update to Pro: the two editions are updated from different addresses.

> ⚠️ **Installing Lite over Pro costs you the settings only Pro has.** The installer says so and goes
> ahead. The forms keep working, with what Lite has; the settings of Pro stay stored until a module
> is next saved, and are dropped at that moment. To go back, install Pro again before saving.
