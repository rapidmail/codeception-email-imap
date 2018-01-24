# Description

This repository offers a Codeception module for testing emails via imap with codeception.

## MailHog

The module was inspired by https://packagist.org/packages/ericmartel/codeception-email-mailhog and has a compatible API, so a test can be configured for local run with MailHog and a run against an external server with imap.

## Imap

This module connects to an imap server and loads the messages from the inbox. The module depends on the composer module ddeboer/imap to use an object oriented structure. 

Important: the php module php-imap needs to be loaded in the cli environment! There are some issues with php from homebrew on OSX, php from https://php-osx.liip.ch/ should work fine as well as installing the module in Debian or Ubuntu.

### Configuration

Like the MailHog module, it needs to be enabled for the targeted environment. If you have multiple recipients, think about collecting them all in ONE inbox, since the module can filter the messages by the targeted recipient.


~~~
modules:
    enabled:
        - ImapMail
    config:
        ImapMail:
          # required, all generated mails should end up in this mailbox
          imapUser: foo@example.org
          # required
          imapPassword: bar
          # required
          imapServer: mail.example.org
          
          # optional, defaults to 143, set the proper port, e.g. 993 for TLS
          imapPort: 993
          # optional, pass flags to the imap connection, e.g. to disable validity check of the ssl cert
          imapFlags: /imap/ssl/novalidate-cert
          
          # optional, defaults to false, remove all mails from the inbox after the scenario run
          deleteEmailsAfterScenario: true
~~~