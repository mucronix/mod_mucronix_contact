/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

(function () {
    'use strict';

    /**
     * Builds the endpoint from the Joomla base path, so that an installation
     * in a subdirectory keeps working.
     */
    function endpoint() {
        var paths = Joomla.getOptions('system.paths') || {};
        var base = paths.base || '';

        return base.replace(/\/$/, '') + '/index.php?option=com_ajax&module=mucronix_contact&format=json&method=send';
    }

    function text(key, fallback) {
        var value = Joomla.Text._(key, '');

        return value === '' ? fallback : value;
    }

    function clearErrors(form) {
        form.querySelectorAll('.mcx-error').forEach(function (node) {
            node.remove();
        });
        form.querySelectorAll('.is-invalid').forEach(function (node) {
            node.classList.remove('is-invalid');
        });
    }

    function showFieldErrors(form, errors) {
        Object.keys(errors).forEach(function (name) {
            var field = form.querySelector('.mcx-field--' + name);

            if (!field) {
                return;
            }

            field.classList.add('is-invalid');

            var note = document.createElement('span');
            note.className = 'mcx-error';
            note.textContent = errors[name];

            // Into our own wrapper, the same place the server puts it when JavaScript is off
            var wrap = field.closest('.mcx-field-wrap') || field.parentElement;
            wrap.appendChild(note);
        });
    }

    function showMessage(box, html, modifier) {
        box.classList.remove('mcx-message--ok', 'mcx-message--error');
        box.classList.add('mcx-message--' + modifier);
        box.innerHTML = html;

        // The block carries aria-live, moving the focus makes it reachable by keyboard as well
        box.setAttribute('tabindex', '-1');
        box.focus();
    }

    /**
     * The proof of work challenge is spent once it has been checked, so the widget
     * needs a new one before the visitor can try again.
     */
    function resetCaptcha(form) {
        form.querySelectorAll('altcha-widget').forEach(function (widget) {
            if (typeof widget.reset === 'function') {
                widget.reset();
            }
        });
    }

    /**
     * Gives the form back to the visitor: one place, so no branch can forget it.
     */
    function release(form, button, label) {
        form.dataset.mcxSending = '';

        if (button) {
            button.disabled = false;
            button.innerHTML = label;
        }
    }

    function submit(form, wrapper) {
        /*
         * One send at a time, whichever way the submit arrived: a click, the Enter key in a text
         * field, or a captcha widget replaying the submission through requestSubmit(). A disabled
         * button stops none of those, so the state lives on the form.
         */
        if (form.dataset.mcxSending === '1') {
            return;
        }

        form.dataset.mcxSending = '1';

        var box = wrapper.querySelector('.mcx-message');
        var button = form.querySelector('.mcx-submit');
        var label = button ? button.innerHTML : '';

        clearErrors(form);

        // Only now, when the request is really going out, does the button change
        if (button) {
            button.disabled = true;
            button.innerHTML = text('MOD_MUCRONIX_CONTACT_FORM_SENDING', 'Sending...');
        }

        var request;

        // Set when the answer sends us to a thank-you page, so no branch hands the form back
        var leaving = false;

        try {
            request = fetch(endpoint(), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        } catch (error) {
            /*
             * Anything thrown before the request exists happens outside the promise, so no finally
             * would ever run and the form would stay silent for good.
             */
            release(form, button, label);
            showMessage(
                box,
                '<div>' + text('MOD_MUCRONIX_CONTACT_ERROR_SEND', 'The message could not be sent.') + '</div>',
                'error'
            );

            return;
        }

        request
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                var data = json && json.data ? json.data : null;

                if (!data) {
                    throw new Error('malformed response');
                }

                if (data.success) {
                    /*
                     * The thank-you page is the answer, so nothing is shown and nothing is hidden
                     * first: the document is about to be replaced, and a message read halfway is
                     * worse than none. Going at once is also the point of the page - an analytics
                     * goal on it needs a real page view, and a delay loses it to a closed tab.
                     */
                    if (data.redirect) {
                        try {
                            window.location.assign(data.redirect);

                            /*
                             * Only once the call has returned. Set before it, a throw would leave
                             * the flag up, finally would hand nothing back and the form would sit
                             * with a dead button for good.
                             */
                            leaving = true;

                            return;
                        } catch (error) {
                            /*
                             * The message did go out, so the thank-you text is the truthful answer
                             * here. Falling through to an error would tell the visitor to write
                             * again, and a second copy is a worse outcome than a missed page.
                             */
                        }
                    }

                    showMessage(box, data.message, 'ok');
                    form.hidden = true;

                    return;
                }

                if (data.errors) {
                    showFieldErrors(form, data.errors);
                }

                showMessage(box, (data.messages || []).map(function (message) {
                    return '<div>' + message + '</div>';
                }).join(''), 'error');

                resetCaptcha(form);
            })
            .catch(function () {
                showMessage(
                    box,
                    '<div>' + text('MOD_MUCRONIX_CONTACT_ERROR_SEND', 'The message could not be sent.') + '</div>',
                    'error'
                );
                resetCaptcha(form);
            })
            .finally(function () {
                /*
                 * Navigation is not instant. Handing the form back in that gap would put the button
                 * on "Send" for a blink and reopen the door to a second submission that still has
                 * time to leave.
                 */
                if (!leaving) {
                    release(form, button, label);
                }
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.mcx').forEach(function (wrapper) {
            var form = wrapper.querySelector('.mcx-form');

            if (!form) {
                return;
            }

            /*
             * The listener sits on the wrapper rather than on the form itself. Submit bubbles, so
             * ours runs after every handler attached to the form, whoever registered first: our
             * script is deferred, the captcha widget is an async module that binds when its element
             * comes alive, and that order is not ours to decide. A widget calling a submission off
             * also stops the event travelling, so it never reaches us and there is nothing to undo.
             */
            wrapper.addEventListener('submit', function (event) {
                // Called off by someone who did not stop the event as well
                if (event.defaultPrevented) {
                    return;
                }

                event.preventDefault();
                submit(form, wrapper);
            });
        });
    });
})();
