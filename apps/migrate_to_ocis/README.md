# migrate-to-ocis

[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE) [![ownCloud OSPO](https://img.shields.io/badge/OSPO-ownCloud-blue)](https://kiteworks.com/opensource)

An ownCloud app that migrates users, groups, files and shares from ownCloud Classic to [ownCloud Infinite Scale (oCIS)](https://github.com/owncloud/ocis).

# Migration overview

The ownCloud Classic to oCIS migration will migrate users, groups, files and shares during several migration steps that will need to be executed in order.

From the initial migration step to the end, every step needs to be executed. Some steps might be skipped if you want to cover different scenarios. However, the migration will only move forward: if a migration step has been successful, then you won't be able to repeat the same step.

The ownCloud Classic instance won't be modified by the migration tool. You might need to apply changes manually in order to fulfill the requirements.

If something goes wrong, you can reset the migration and start from the beginning. Note that changes applied in the target oCIS server will still be there and might cause problems, so it's recommended that the oCIS server is fresh clean.

# Regular migration

The following instructions covers the migration of an ownCloud Classic instance with local (non-LDAP) users. Instructions for a migration with LDAP users will be covered in a different section.

Notes:
* Users will be created with an unknown password. This means that users won't be able to login normally. If there is no onboarding process in place, the admin will need to manually setup a temporary password for each user so they can login and change the password.
* Passwords for the migrated share links will be unknown. Although the actual password for the migrated link might be figured out, the password won't usually match the one used for ownCloud Classic. Users should change the password of all their share links.
* By default, oCIS will require passwords for share links. As such, passwordless links won't be migrated because that might be considered a security risk by the oCIS instance. You can change that policy in oCIS and allow passwordless links before the migration starts if that's a problem.

## ownCloud Classic requirements

* Disabled users WON'T be migrated. The migration will keep going though.
  * Groups won't have those users as member
  * Files owned by those users won't be migrated
  * Shares created by those users won't be migrated
  * Shares targeting those users will fail to be migrated (and error will show but the process will keep going)
* All enabled users must have a valid email
* There must not be any duplicated email

While some of these checks can be skipped, failing to comply might cause problems during the migration. It's admin's responsibility to ensure those requirements are fulfilled.

## oCIS requirements

* Auth-app enabled, with impersonation active. Use the following env vars:
  ```
  OCIS_ADD_RUN_SERVICES: "auth-app"
  PROXY_ENABLE_APP_AUTH: true
  AUTH_APP_ENABLE_IMPERSONATION: true
  ```

oCIS, by default, requires share links to have a password. This can be changed via env vars if needed (`OCIS_SHARING_PUBLIC_SHARE_MUST_HAVE_PASSWORD`).
While changing the env var isn't required, ownCloud Classic passwordless links will fail to be migrated if the setting is active.

An app token must be created for the oCIS admin user using the auth-app app. Both the admin user and the created token will be used as username + password authentication for oCIS during the migration.

## Migration steps

### `migrate:to-ocis:init`

```
occ migrate:to-ocis:init -k ocis.example.com
```

Initialize the migration to the "ocis.example.com" oCIS instance.

The `-k` option will cause all the connections during the migration to ignore the SSL certificate of the oCIS instance (if you trust the server and don't want to deal with SSL certificates).

The `-f` option will reset the migration process. Note that this won't do anything to the target oCIS instance ("ocis.example.com" in this case) and you should ensure that the target oCIS instance is fresh. If the oCIS instance contains data you need to use a different one.

The `--skip` option will be ignored.

Note that both the oCIS host and the `-k` option will be stored and used during the migration.

### `migrate:to-ocis:verify`

```
ocis migrate:to-ocis:verify
```

Verify that the ownCloud Classic installation (where the app is running) can be migrated. This basically ensure that all the enabled users has valid non-duplicate emails.

If there are problems with the emails, the migration will stop here until the problems are solved.

Note that disabled users will be shown as part of the verification. Those users WON'T be migrated, and they won't stop the migration.

If there is a disabled user that should be migrated, you can enable the user after the verification. However, the migration might have moved to the next step and you won't be able to verify the installation.
The recommended action is to reset the migration (check the `migrate:to-ocis:init` command) so you can go through the verification process again. Otherwise, you take the risk of the user to cause problems with the email.

You can skip this step with the `--skip` option, but it's your responsibility if there are problems due to duplicate emails.

### `migrate:to-ocis:migrate:users`

```
occ migrate:to-ocis:migrate:users admin
```

Migrate the ownCloud Classic users using the oCIS admin account "admin". The password for the admin account will be asked interactively.

Disabled users won't be migrated.

This step can be skipped for other scenarios (such as LDAP), but it shouldn't be skipped in this case.

New users will be created in oCIS matching the ownCloud Classic users. If the users can't be created because they already exists ("admin" account is likely one of these cases), we'll try to reuse those accounts.

### `migrate:to-ocis:assign-role`

```
occ migrate:to-ocis:assign-role admin
```

Assign the user role using the admin account "admin". The password for the admin account will be asked interactively.

The role to be assigned will also be asked interactively. Available roles will be fetched from the oCIS instance.

The same role will be applied to all the migrated users (\*) except the admin.

(\*) We'll go through the ownCloud Classic user list and find those users in the oCIS instance. Users out of that list won't be taken into account.

This step is mandatory, so the skip option will be ignored.

### `migrate:to-ocis:migrate:groups`

```
occ migrate:to-ocis:migrate:groups admin
```

Migrate the ownCloud Classic groups using the oCIS admin account "admin". The password will be asked interactively.

The groups will be migrated, and the users belonging to the groups will be assigned accordingly. If a user isn't found in oCIS, maybe because it's disabled and hasn't been migrated, the assignment will fail and it will be skipped. Skipping users won't fail the migration.

Same with the users, if a group fails to be created we'll try to find it in the oCIS instance to reuse that group.

This step can be skipped if needed. Note that shares targeting groups might fail to be created if those groups aren't found in oCIS.

### `migrate:to-ocis:migrate:files`

```
occ migrate:to-ocis:migrate:files admin
```

Migrate the files of each user using the rclone binary (included in the app). oCIS impersonation (via auth-app) will be used to access each user's account.

There are a couple of exceptions:
* Users that have never logged in: they'll be skipped for the file migration because they won't have any file.
* Users not found in oCIS: they'll also be skipped. This is usually caused by disabled users that haven't been migrated.

Note that skipping users won't stop the migration.

### `migrate:to-ocis:migrate:shares`

```
occ migrate:to-ocis:migrate:shares admin
```

Migrate the shares of each user. This includes user, group and link shares. oCIS impersonation (via auth-app) will be used to access each user's account.

Same exceptions as with the file migration:
* Users that have never logged in: they'll be skipped for the share migration because they won't have shared any file.
* Users not found in oCIS: they'll also be skipped. This is usually caused by disabled users that haven't been migrated.

Sharing has several point of failures that won't stop the migration. An error will be shown, but the migration will keep going:
* Shares pointing to missing users or groups (that haven't been migrated)
* Password restrictions in shared links

For password-protected links, note that, although a password will be used to protect the file, the password will be unknown and the link won't be easily accessible. In fact, the password used in oCIS WON'T match the one used in ownCloud Classic. It's advised that users change the password of all their share links once the migration is over.


# Migration with LDAP users using the internal oCIS' IDP

ownCloud Classic might be connected to an LDAP server. In this case, the migration will be slightly different.

We'll assume that the setup for ownCloud Classic with LDAP is mostly the default. Changes needed outside of the default values will be discussed.

## ownCloud Classic requirements

* Same as with the regular migration, disabled users won't be migrated
* LDAP users MUST have the email attribute set (LDAP wizard -> advanced tab -> email field; usually "mail")
* LDAP users should have the username attribute set.
  * By default, the internal username attribute (LDAP wizard -> expert tab -> internal username attribute; "entryUUID" / "objectGUID" if empty) will be used. This is usually wrong for the migration.
  * The attribute that we need to change is only available via CLI, but it can be adjusted after the ownCloud Classic setup (unlike the internal username above).
    The recommended attribute to use is the "uid" or "samAccountName". Run `occ ldap:set-config '' ldapUserName uid` to change the attribute to uid. You'll need to re-sync the users afterwards to bring the changes.

## oCIS requirements

* Auth-app enabled, with impersonation active. Use the following env vars:
  ```
  OCIS_ADD_RUN_SERVICES: "auth-app"
  PROXY_ENABLE_APP_AUTH: true
  AUTH_APP_ENABLE_IMPERSONATION: true
  ```

Verify the following env vars to ensure they're correct:
* The `OCIS_LDAP_USER_ENABLED_ATTRIBUTE` must exists and not be false. The default value uses the ownCloud schema that is unlikely to be present, so you might want to use a different attribute.
* `OCIS_LDAP_USER_SCHEMA_ID` and `OCIS_LDAP_GROUP_SCHEMA_ID`, same as above. "entryUUID" / "objectGUID" might be used instead.
  * "entryUUID" isn't an octet string and might not require the `OCIS_LDAP_USER_SCHEMA_ID_IS_OCTETSTRING` (and group) flag
* `OCIS_ADMIN_USER_ID` based on the configured `OCIS_LDAP_USER_SCHEMA_ID`. Following the above information, the "entryUUID" of the LDAP user that will act as admin needs to be provided.

You can use the following vars as template:
```
# "true" disables LDAP TLS certificate verification. Never use in production.
OCIS_LDAP_INSECURE: "false"

OCIS_LDAP_URI: ldap://ldap.example.com:389
OCIS_LDAP_BIND_DN: "cn=admin,dc=example,dc=com"
OCIS_LDAP_BIND_PASSWORD: "${LDAP_BIND_PASSWORD}"

OCIS_LDAP_GROUP_BASE_DN: "ou=groups,dc=example,dc=com"
OCIS_LDAP_GROUP_FILTER: "(objectclass=groupOfNames)"
OCIS_LDAP_GROUP_OBJECTCLASS: "groupOfNames"

OCIS_LDAP_USER_BASE_DN: "ou=people,dc=example,dc=com"
OCIS_LDAP_USER_FILTER: "(objectclass=inetOrgPerson)"
OCIS_LDAP_USER_OBJECTCLASS: "inetOrgPerson"

OCIS_LDAP_USER_ENABLED_ATTRIBUTE: "employeeType"
IDP_LDAP_LOGIN_ATTRIBUTE: "uid"

OCIS_LDAP_GROUP_SCHEMA_ID: "entryUUID"
OCIS_LDAP_USER_SCHEMA_ID: "entryUUID"
OCIS_ADMIN_USER_ID: "<admin-user-entryUUID>"
```

Using the default values for the rest of the LDAP related vars should be fine, although you might need to double check. If possible use the `OCIS_LDAP_*` variants so the changes are applied to all the related services.

Access to the LDAP users must be available before starting the migration. You might want to login with some users before the migration to ensure the LDAP setup is correct.

oCIS, by default, requires share links to have a password. This can be changed via env vars if needed (`OCIS_SHARING_PUBLIC_SHARE_MUST_HAVE_PASSWORD`).
While changing the env var isn't required, ownCloud Classic passwordless links will fail to be migrated if the setting is active.

An app token must be created for the oCIS admin user using the auth-app app. Both the admin user and the created token will be used as username + password authentication for oCIS during the migration.

## Migration steps

The migration steps, in general, will be the same as with the regular migration, although some steps will be skipped. Check the regular migration for details on each step.

```
occ migrate:to-ocis:init -k ocis.example.com
occ migrate:to-ocis:verify
occ migrate:to-ocis:migrate:users --skip admin
occ migrate:to-ocis:assign-role admin
occ migrate:to-ocis:migrate:groups --skip admin
occ migrate:to-ocis:migrate:files admin
occ migrate:to-ocis:migrate:shares admin
```

Both user and group migration will be skipped because the users and groups will come from the LDAP server, so we can consider them to be migrated. However, local (non-LDAP) users and groups won't be migrated. If you need any of those users or groups, you'll need to create them manually in oCIS before starting the migration; make sure the usernames and groupnames match so they can be found.
Taking this into account, only LDAP users (and manually "migrated" users) will have their files and shares migrated.

Note that assigning a role to the users is still mandatory despite skipping the user migration.

# Contributing

We welcome contributions! Please read the [Contributing Guidelines](CONTRIBUTING.md) and our [Code of Conduct](CODE_OF_CONDUCT.md) before getting started.

# Support

For support options, see [SUPPORT.md](SUPPORT.md).

# Security

**Do not open a public GitHub issue for security vulnerabilities.** See [SECURITY.md](SECURITY.md) for how to report them responsibly.

# License

This project is licensed under the [Apache License 2.0](LICENSE).
