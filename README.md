OCHA Entra ID
=============

Integration with Microsoft Entra ID to login or register new accounts.

This module provides a `/user/login/entraid` route to a sign in page and a `/user/register/entraid` route to a registration page.

It also provides a `/user/login/entraid-direct` route to directly access the Microsoft Entra ID sign in page.

## Requirements

This module requires that an `entraid` OpenID Connect client is properly configured in order to sign in.

See the [OpenID Connect Microsoft Azure Active Directory client](https://www.drupal.org/project/openid_connect_windows_aad) module for that purpose.
