---
title: Notices on WhatsApp
category: Administration
order: 90
staff: true
routes: account.profile
---

# Notices on WhatsApp

The Workspace mails staff when something waits for them: a request for access, a workflow task, a name to resolve, a repatriation claim, a fixity failure, an embargo ending, a DOI. Any member of staff can have those notices on WhatsApp too.

## Asking for them

On your **Profile**, under **WhatsApp**, give your mobile number (082 123 4567 or +27 82 123 4567) and tick **Send my notices to WhatsApp too**. Mail still comes as before. Untick it, or empty the number, to stop.

The section appears only once the installation has WhatsApp on, and only to staff: an account with a role beyond simply being signed in. Researchers and the public are never sent anything on WhatsApp.

## What arrives

WhatsApp lets a business start a conversation only with a message Meta approved in advance, so every notice has the same shape:

> A notice from Riverside Archives: A request for access waits for review. Open https://... to see it.

The middle is the subject of the mail, and the link opens the page it is about. Nothing else of the mail is sent.

## For the installation

The Workspace never holds the WhatsApp credential. It writes each notice into the estate's spool and the estate's sender, `ahg-whatsapp-watcher`, sends it from the business number. It is off until `WHATSAPP_ENABLED` is set, which waits for Meta to approve the template `ahg_system_notice` (see INSTALL.md). A notice that cannot be written is logged without the number, and never stops the mail.
