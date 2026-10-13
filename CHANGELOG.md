# Changelog

## 2.0.0

The first public version, in two editions built from one source: **Mucronix Contact Lite**, free,
and **Mucronix Contact Pro**. Both are the module `mod_mucronix_contact`, for Joomla 6.0 or later
and PHP 8.3 or later, with the Behaviour Backward Compatibility plugin switched off.

### In both editions

- The message goes to the site owner by email: Recipient, Subject, Intro Text, Button Text,
  Success Message.
- Captcha — Use Global or a chosen one — and a honeypot.
- One message in 30 seconds per session.
- The Name, Email and Message fields, each shown or hidden, required or not.
- Consent Checkbox with a Policy Article and a Consent Text.
- Appearance: Field Markup (Bootstrap 5 / UIkit / No Added Classes) and Own Styles
  (Full / Basic / None).
- The CSS variables `--mcx-*`, to be set in the template.
- Sending by AJAX, and working without JavaScript.
- A log of errors.
- A notice above the form for site managers when a setting keeps the form from working.
- A check for Joomla 6.0+ and PHP 8.3+ on installation.
- The version and the edition in the first line of the Module tab.
- English, Russian and Ukrainian.

### In Pro only

- Additional Recipients.
- Thank You Page.
- Attachment Field, with its accepted types and size limit.
- Extra Fields, with a page of notes on them.
- Telegram.
- Appearance: Field Look, Border Colour, Corner Radius, Space Between Fields, Frame Around the
  Form, Form Background, Form Width, Button Look, Accent Colour.
- Appearance: Wrapper, Form, Field and Button Class, and Custom CSS.
- The full page of styling notes; Lite has a short one, on the CSS variables and on overriding the
  layout.
- Log Sent Messages.

### Changing edition

- Pro is installed over Lite the ordinary way. The settings of every form stay as they are.
- Lite can be installed over Pro. The installer warns that the settings of Pro are dropped the next
  time a module is saved, and removes the files only Pro uses.
- Each edition is updated from an address of its own, and neither is ever offered the other. Pro
  needs a download key for its updates.
