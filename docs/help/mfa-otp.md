---
title: Two-factor sign-in and single sign-on
category: Administration
order: 40
staff: true
routes: two-factor.*, sign-in-settings.*
heratio: mfa-otp.md
---

# Two-factor sign-in and single sign-on

## Two-factor sign-in

A password can be guessed or stolen. Two-factor sign-in asks, after the password, for a six-digit code from an authenticator app on your phone, such as Google Authenticator, Microsoft Authenticator or Aegis, so a stolen password alone is not enough.

**Setting it up.** Under your account, **Two-factor sign-in** (`/account/two-factor`): scan the QR code with your app, type the code it shows to confirm, and keep the **recovery codes** somewhere safe. Each recovery code works once, in place of a code from the app, if you lose your phone.

**Signing in.** After your password, type the code your app shows. A code works for a short while and only once.

**Lost phone and codes.** An administrator can turn two-factor sign-in off for your account; you set it up again at your next sign-in if it is required.

## The institution's policy

**Sign-in settings** (`/sign-in-settings`, for those who manage accounts) sets who must use it:

| Policy | Who |
|---|---|
| Off | Nobody is asked |
| Optional | Anyone may set it up |
| Required for administrators | Those who administer the institution must |
| Required | Everyone must |

Where it is required, a person who has not set it up has some days to do so, as the institution sets, before they can do nothing else. A security classification level can also require it before its records are read.

## Single sign-on

An institution that has its own identity provider, such as Microsoft Entra ID, Google Workspace, Keycloak or any OpenID Connect provider, can let its staff sign in with it. Under **Identity providers**, **Add an identity provider**: its issuer address, client ID and secret (kept encrypted and never shown), the scopes it needs, and the name on the button, after *Sign in with*.

Choose **how a sign-in finds its account**: by verified e-mail, which links an existing account the first time it signs in; or by the provider's subject, which links only accounts approved from a request. A sign-in that matches no account can become a request for one.

Roles can follow the provider: name the claim that holds them, such as `roles`, and which role each value gives. Roles are added at each sign-in, never taken away.
